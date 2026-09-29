<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Enums\RequestStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes audit entries. The single place that touches `audit_logs`.
 *
 * Request context (IP, user agent) is read from the current request when there
 * is one, so a console command or queued job records cleanly with nulls rather
 * than failing.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Model $subject,
        string $action,
        ?User $actor = null,
        ?RequestStatus $from = null,
        ?RequestStatus $to = null,
        ?string $remarks = null,
        array $metadata = [],
    ): AuditLog {
        return AuditLog::create([
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role?->slug,
            'remarks' => $remarks,
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255) ?: null,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * Sign-in events — SECURITY.md section 5: "login success/failure".
     *
     * A failed attempt may name an account that does not exist, so there may
     * be no model to attach it to. Such rows use auditable_type "auth" with id
     * 0 and keep the attempted email in metadata. The password is never passed
     * here in any form.
     */
    public function recordAuth(string $action, ?User $user, string $email): AuditLog
    {
        return AuditLog::create([
            'auditable_type' => $user?->getMorphClass() ?? 'auth',
            'auditable_id' => $user?->id ?? 0,
            'action' => $action,
            'actor_id' => $action === 'LOGIN_SUCCEEDED' ? $user?->id : null,
            'actor_role' => $action === 'LOGIN_SUCCEEDED' ? $user?->role?->slug : null,
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255) ?: null,
            'metadata' => ['email' => mb_substr(strtolower(trim($email)), 0, 150)],
        ]);
    }

    /**
     * The history trail for one record, oldest first.
     *
     * @return Collection<int, AuditLog>
     */
    public function historyFor(Model $subject)
    {
        return AuditLog::query()
            ->where('auditable_type', $subject->getMorphClass())
            ->where('auditable_id', $subject->getKey())
            ->with('actor')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
