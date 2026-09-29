<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\NotificationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'booking_request_id', 'event_key', 'channel',
        'title', 'body', 'action_url', 'status',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }

    // ------------------------------------------------------------------- scopes

    /**
     * What the bell shows: in-app entries only.
     *
     * Email and SMS rows are records of delivery attempts, not things to display —
     * showing them would double every notification in the dropdown.
     */
    public function scopeForBell(Builder $q, int $userId): Builder
    {
        return $q->where('user_id', $userId)
            ->where('channel', 'IN_APP')
            ->latest('id');
    }

    public function scopeUnread(Builder $q): Builder
    {
        return $q->whereNull('read_at');
    }

    public function scopeFailed(Builder $q): Builder
    {
        return $q->where('status', 'FAILED');
    }

    // ------------------------------------------------------------------ helpers

    public function event(): ?NotificationEvent
    {
        return NotificationEvent::tryFrom($this->event_key);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markRead(): void
    {
        if ($this->read_at !== null) {
            return;
        }

        $this->read_at = now();
        $this->status = 'READ';
        $this->save();
    }

    /**
     * Tone classes for the bell entry, from the event where possible.
     */
    public function toneClasses(): string
    {
        return match ($this->event()?->tone() ?? 'neutral') {
            'success' => 'bg-[--color-success-bg] text-[--color-success]',
            'danger' => 'bg-[--color-danger-bg] text-[--color-danger]',
            'attention' => 'bg-[--color-attention-bg] text-[--color-attention]',
            default => 'bg-[--color-surface-sunken] text-[--color-ink-muted]',
        };
    }

    /**
     * Relative time for the dropdown, e.g. "4 minutes ago".
     */
    public function age(): string
    {
        return $this->created_at->diffForHumans(['parts' => 1, 'short' => false]);
    }
}
