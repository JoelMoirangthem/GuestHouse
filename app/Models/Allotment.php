<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\AllotmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Allotment extends Model
{
    use HasFactory;

    /**
     * Workflow columns are excluded from mass assignment: status, the *_by
     * stamps and the money fields are all set by AllotmentService after the
     * state machine has agreed.
     */
    protected $fillable = [
        'room_id',
        'check_in_date',
        'check_out_date',
        'occupants_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => AllotmentStatus::class,
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'allotted_at' => 'datetime',
            'actual_check_in_at' => 'datetime',
            'actual_check_out_at' => 'datetime',
            'rate_per_night' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'occupants_count' => 'integer',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function allottedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allotted_by');
    }

    // ------------------------------------------------------------------- scopes

    /** Allotments that still hold their room. */
    public function scopeOccupying(Builder $q): Builder
    {
        return $q->whereIn('status', AllotmentStatus::occupyingValues());
    }

    /**
     * THE OVERLAP PREDICATE — SCHEMA.md section 14.
     *
     *     existing.check_in < new.check_out  AND  new.check_in < existing.check_out
     *
     * Half-open interval [check_in, check_out). A stay ending on the 22nd and one
     * starting on the 22nd do NOT overlap, so the checkout day is immediately
     * reusable. Using a closed interval here would silently lose one night of
     * capacity on every single booking.
     */
    public function scopeOverlapping(Builder $q, string $from, string $to): Builder
    {
        return $q->where('check_in_date', '<', $to)
            ->where('check_out_date', '>', $from);
    }

    // ------------------------------------------------------------------ helpers

    public function nights(): int
    {
        return (int) $this->check_in_date->startOfDay()->diffInDays($this->check_out_date->startOfDay());
    }

    /**
     * Nights actually stayed, for an early checkout — REPORTS.md section 3.
     *
     * Anchored on actual_check_in_at, not the scheduled date: a guest who arrived
     * a day late and left early must not be billed for the absent night. At least
     * one night is always charged.
     */
    public function nightsStayed(): int
    {
        if ($this->actual_check_in_at === null || $this->actual_check_out_at === null) {
            return $this->nights();
        }

        $in = Carbon::parse($this->actual_check_in_at)->startOfDay();
        $out = Carbon::parse($this->actual_check_out_at)->startOfDay();

        return max(1, (int) $in->diffInDays($out));
    }

    public static function nextAllotmentNo(): string
    {
        return \App\Infrastructure\Persistence\NumberSequence::formatted('ALT');
    }

    public static function freshQrToken(): string
    {
        return Str::random(40);
    }
}
