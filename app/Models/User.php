<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\RoleSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\RoutesNotifications;

class User extends Authenticatable
{
    /*
     * RoutesNotifications, not the full Notifiable trait. Notifiable also brings
     * HasDatabaseNotifications, whose notifications() relation expects Laravel's
     * polymorphic table (notifiable_type / notifiable_id). This application has
     * its own notifications table keyed by user_id, so that relation would fail
     * with a SQL error. notify() — used by the password-reset mail — lives in
     * RoutesNotifications and keeps working.
     */
    use HasFactory, RoutesNotifications, SoftDeletes;

    /**
     * Note what is absent: `role_id` is deliberately NOT mass-assignable.
     * Privilege escalation via a crafted form field is one of the cheapest
     * attacks on a role-based system, so role changes go through an explicit
     * admin action rather than a fillable attribute (SECURITY.md section 6).
     */
    protected $fillable = [
        'employee_code',
        'name',
        'email',
        'mobile',
        'designation',
        'department',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    // ---------------------------------------------------------------- relations

    protected static function booted(): void
    {
        // Every password change, by any path, lands in the reuse history.
        static::saved(function (User $user): void {
            if ($user->wasRecentlyCreated || $user->wasChanged('password')) {
                \App\Application\Services\PasswordHistory::record($user);
            }
        });
    }

    // ---------------------------------------------------------------- relations

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function reportingManager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reporting_manager_id');
    }

    /** Users who report to this user — defines "team" scope in REPORTS.md section 6. */
    public function reportees(): HasMany
    {
        return $this->hasMany(self::class, 'reporting_manager_id');
    }

    /** Every notification row addressed to this user, across all channels. */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    // ------------------------------------------------------------ role helpers

    public function hasRole(RoleSlug ...$slugs): bool
    {
        $mine = $this->role?->slug;

        if ($mine === null) {
            return false;
        }

        foreach ($slugs as $slug) {
            if ($mine === $slug->value) {
                return true;
            }
        }

        return false;
    }

    public function isUser(): bool
    {
        return $this->hasRole(RoleSlug::USER);
    }

    public function isManager(): bool
    {
        return $this->hasRole(RoleSlug::MANAGER);
    }

    public function isAdg(): bool
    {
        return $this->hasRole(RoleSlug::ADG);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(RoleSlug::ADMIN);
    }

    /**
     * Permission check against the role's seeded permission set.
     *
     * This is the second of the three authorization layers in SECURITY.md
     * section 4. The route middleware is the first; policies are the third.
     * All three must agree, so that removing a button is never the only thing
     * standing between a role and an action it must not perform.
     */
    public function hasPermission(string $slug): bool
    {
        return $this->role
            ?->permissions
            ->contains(fn (Permission $p) => $p->slug === $slug) ?? false;
    }

    public function roleSlug(): ?RoleSlug
    {
        return $this->role?->slugEnum();
    }

    /**
     * Initials for the avatar chip in the header.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return '?';
        }

        if (count($parts) === 1) {
            return strtoupper(mb_substr($parts[0], 0, 2));
        }

        return strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1));
    }
}
