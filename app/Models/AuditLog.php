<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * An append-only audit entry.
 *
 * The guards below are belt and braces on top of "we simply never call update()".
 * A future contributor who tries will get a loud exception rather than silently
 * rewriting an approval history.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;   // the table has no updated_at column

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'action',
        'from_status',
        'to_status',
        'actor_id',
        'actor_role',
        'remarks',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(
                'Audit log entries are append-only and must never be modified.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'Audit log entries are append-only and must never be deleted.'
            );
        });
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Human-readable description for the History panel on screen 3.4.
     */
    public function describe(): string
    {
        return match ($this->action) {
            'SUBMITTED' => 'Request submitted by the applicant',
            'RESUBMITTED' => 'Applicant supplied the requested information',
            'MANAGER_APPROVED' => 'Approved by the Manager',
            'MANAGER_REJECTED' => 'Rejected by the Manager',
            'MORE_INFO_REQUESTED' => 'Manager asked for more information',
            'ADG_APPROVED' => 'Approved by the ADG',
            'ADG_REJECTED' => 'Rejected by the ADG',
            'AVAILABILITY_CHECKED' => 'Availability checked by the Administration',
            'ROOMS_ALLOTTED' => 'Rooms allotted',
            'ROOMS_HELD' => 'Rooms selected and held by the Manager',
            'ROOMS_CONFIRMED' => 'Manager-selected rooms confirmed on ADG approval',
            'ROOMS_RELEASED' => 'Held rooms released',
            'NO_ROOM_AVAILABLE' => 'Marked as no room available',
            'AVAILABILITY_RECHECK' => 'Availability re-checked',
            'CHECKED_IN' => 'Guest checked in',
            'CHECKED_OUT' => 'Guest checked out',
            'EARLY_CHECKOUT' => 'Guest checked out early',
            'EXTENSION_REQUESTED' => 'Extension requested',
            'EXTENSION_APPROVED' => 'Extension approved',
            'EXTENSION_DENIED' => 'Extension denied',
            'CANCELLED' => 'Request cancelled',
            'DOCUMENT_VIEWED' => 'Identity document viewed',
            'USER_CREATED' => 'Account created',
            'USER_UPDATED' => 'Account details updated',
            'ROLE_CHANGED' => 'Role changed',
            'USER_DEACTIVATED' => 'Account deactivated',
            'USER_REACTIVATED' => 'Account reactivated',
            'ROOM_CREATED' => 'Room added',
            'ROOM_UPDATED' => 'Room details updated',
            'ROOM_BLOCKED' => 'Room taken out of service',
            'ROOM_UNBLOCKED' => 'Room returned to service',
            'ROOM_TYPE_CREATED' => 'Room type added',
            'ROOM_TYPE_UPDATED' => 'Room type updated',
            'TARIFF_CREATED' => 'Tariff added',
            'TARIFF_ENDED' => 'Tariff end date set',
            'FEEDBACK_SUBMITTED' => 'Guest submitted feedback',
            'HOLIDAY_CREATED' => 'Holiday added',
            'HOLIDAY_UPDATED' => 'Holiday updated',
            'HOLIDAY_DELETED' => 'Holiday removed',
            'SETTINGS_UPDATED' => 'Settings changed',
            'TEMPLATE_UPDATED' => 'Notification template edited',
            'ID_PROOF_REVEALED' => 'Full identity number revealed',
            'ID_PROOFS_PURGED' => 'Identity documents deleted (retention period ended)',
            'LOGIN_SUCCEEDED' => 'Signed in',
            'LOGIN_FAILED' => 'Failed sign-in attempt',
            default => $this->action,
        };
    }
}
