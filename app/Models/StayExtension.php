<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StayExtension extends Model
{
    use HasFactory;

    /**
     * Only the applicant's own inputs are mass-assignable. Status and the
     * decision columns are set by StayService.
     */
    protected $fillable = [
        'requested_check_out_date',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'previous_check_out_date' => 'date',
            'requested_check_out_date' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }

    public function allotment(): BelongsTo
    {
        return $this->belongsTo(Allotment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', 'REQUESTED');
    }

    public function isPending(): bool
    {
        return $this->status === 'REQUESTED';
    }

    /** Extra nights being asked for. */
    public function additionalNights(): int
    {
        return (int) $this->previous_check_out_date->startOfDay()
            ->diffInDays($this->requested_check_out_date->startOfDay());
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'REQUESTED' => 'Awaiting decision',
            'APPROVED' => 'Approved',
            'DENIED' => 'Denied',
            default => $this->status,
        };
    }

    public function statusClasses(): string
    {
        return match ($this->status) {
            'REQUESTED' => 'bg-[--color-extend-bg] border-violet-200 text-[--color-extend]',
            'APPROVED' => 'bg-[--color-success-bg] border-green-200 text-[--color-success]',
            'DENIED' => 'bg-[--color-danger-bg] border-red-200 text-[--color-danger]',
            default => 'bg-[--color-neutral-bg] border-[--color-line-strong] text-[--color-neutral]',
        };
    }
}
