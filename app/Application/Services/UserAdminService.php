<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Models\BookingRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Account provisioning — ROUTES.md "Masters", SECURITY.md section 3.
 *
 * There is no self-registration, so this service is the only way an account comes
 * into existence. It owns the rules that a plain CRUD form would get wrong:
 *
 *   - role_id is not mass-assignable on the model, so role changes happen here,
 *     explicitly, and are audited (SECURITY.md section 5)
 *   - an administrator cannot demote or deactivate themselves, which also
 *     guarantees the institution can never lock itself out of its own system
 *   - a manager still holding requests awaiting their review cannot be demoted
 *     or deactivated, because those requests carry manager_id and would be
 *     stranded with nobody able to act on them
 *   - deactivation and password changes end every live session for that user
 */
class UserAdminService
{
    /** Fields copied straight from validated input; everything else is explicit. */
    private const PROFILE_FIELDS = [
        'employee_code', 'name', 'email', 'mobile', 'designation', 'department',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(array $data, User $admin): User
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($data, $admin) {
            $role = Role::findOrFail((int) $data['role_id']);
            $manager = $this->resolveManager($data['reporting_manager_id'] ?? null, null, $role);

            $user = new User;
            $user->fill(array_intersect_key($data, array_flip(self::PROFILE_FIELDS)));
            $user->password = (string) $data['password'];
            $user->role_id = $role->id;
            $user->reporting_manager_id = $manager?->id;
            $user->is_active = (bool) ($data['is_active'] ?? true);
            $user->save();

            $this->audit->record($user, 'USER_CREATED', $admin, metadata: [
                'role' => $role->slug,
                'reporting_manager_id' => $manager?->id,
            ]);

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function update(User $user, array $data, User $admin): User
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($user, $data, $admin) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $newRole = Role::findOrFail((int) $data['role_id']);
            $oldRole = $user->role;
            $newActive = (bool) ($data['is_active'] ?? false);

            $roleChanging = $oldRole?->id !== $newRole->id;
            $deactivating = $user->is_active && ! $newActive;
            $reactivating = ! $user->is_active && $newActive;

            // Because only an active administrator can reach this method, refusing
            // self-demotion is also what guarantees at least one administrator
            // always remains: nobody can remove the last one but themselves.
            if ($user->is($admin) && ($roleChanging || $deactivating)) {
                throw new RuntimeException(
                    'You cannot change your own role or deactivate your own account. Ask another administrator.'
                );
            }

            if ($oldRole?->slug === RoleSlug::MANAGER->value && ($roleChanging || $deactivating)) {
                $this->assertNoPendingReviews($user);
            }

            $manager = $this->resolveManager($data['reporting_manager_id'] ?? null, $user, $newRole);

            $user->fill(array_intersect_key($data, array_flip(self::PROFILE_FIELDS)));
            $user->role_id = $newRole->id;
            $user->reporting_manager_id = $manager?->id;
            $user->is_active = $newActive;

            $passwordChanged = filled($data['password'] ?? null);
            if ($passwordChanged) {
                if (\App\Application\Services\PasswordHistory::wasRecentlyUsed($user, (string) $data['password'])) {
                    throw new RuntimeException(\App\Application\Services\PasswordHistory::MESSAGE);
                }
                $user->password = (string) $data['password'];
                $user->remember_token = null;
            }

            // Captured before save(): getDirty() is empty afterwards.
            $changed = array_values(array_diff(array_keys($user->getDirty()), ['password', 'remember_token', 'updated_at']));
            $user->save();

            if ($changed !== [] || $passwordChanged) {
                // Field NAMES only. Values may be personal data and have no place
                // in an audit row; the password is never recorded in any form.
                $this->audit->record($user, 'USER_UPDATED', $admin, metadata: array_filter([
                    'fields' => $changed,
                    'password_reset' => $passwordChanged ?: null,
                ]));
            }

            if ($roleChanging) {
                $this->audit->record($user, 'ROLE_CHANGED', $admin, metadata: [
                    'from' => $oldRole?->slug,
                    'to' => $newRole->slug,
                ]);
            }

            if ($deactivating) {
                $this->audit->record($user, 'USER_DEACTIVATED', $admin);
            } elseif ($reactivating) {
                $this->audit->record($user, 'USER_REACTIVATED', $admin);
            }

            // Any change of standing ends the user's live sessions, so a demoted
            // or deactivated account cannot keep acting on a session opened
            // before the change.
            if ($deactivating || $roleChanging || $passwordChanged) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            return $user->refresh();
        });
    }

    /**
     * Active managers an employee may report to.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function managerOptions(?User $except = null)
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', RoleSlug::MANAGER->value))
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->orderBy('name')
            ->get();
    }

    // ---------------------------------------------------------------- internals

    /**
     * A regular employee must have a reporting manager: without one, a submitted
     * request has nobody to route to and BookingRequestService refuses it. For
     * the other roles a manager is optional.
     */
    private function resolveManager(mixed $managerId, ?User $subject, Role $role): ?User
    {
        if (blank($managerId)) {
            if ($role->slug === RoleSlug::USER->value) {
                throw new RuntimeException('An employee must have a reporting manager, or their requests cannot be routed.');
            }

            return null;
        }

        $manager = User::with('role')->find((int) $managerId);

        if ($manager === null || ! $manager->isManager() || ! $manager->is_active) {
            throw new RuntimeException('The reporting manager must be an active user with the Manager role.');
        }

        if ($subject !== null && $manager->is($subject)) {
            throw new RuntimeException('A user cannot be their own reporting manager.');
        }

        return $manager;
    }

    private function assertNoPendingReviews(User $manager): void
    {
        $pending = BookingRequest::query()
            ->where('manager_id', $manager->id)
            ->whereIn('status', [RequestStatus::PENDING_MANAGER->value, RequestStatus::MORE_INFO_MANAGER->value])
            ->count();

        if ($pending > 0) {
            throw new RuntimeException(
                "This manager still has {$pending} request(s) awaiting their review. "
                .'Those requests would be stranded; ask the manager to decide them first.'
            );
        }
    }

    private function assertAdmin(User $actor): void
    {
        if (! $actor->hasPermission('master.manage')) {
            throw new RuntimeException('Only an administrator may manage user accounts.');
        }
    }
}
