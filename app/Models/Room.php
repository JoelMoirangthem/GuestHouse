<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\RoomStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_number', 'room_type_id', 'floor', 'block',
        'capacity', 'status', 'block_reason', 'blocked_from', 'blocked_to', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'blocked_from' => 'date',
            'blocked_to' => 'date',
            'capacity' => 'integer',
            'floor' => 'integer',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function allotments(): HasMany
    {
        return $this->hasMany(Allotment::class);
    }

    /**
     * Capacity falls back to the room type's default when not overridden.
     */
    public function effectiveCapacity(): int
    {
        return $this->capacity ?? $this->roomType->default_capacity;
    }

    /**
     * Whether a date-ranged block overlaps the given window.
     *
     * An open-ended block (blocked_to NULL) extends indefinitely. Getting this
     * wrong is exactly the SQL defect the specification audit caught: a bare
     * comparison against NULL yields NULL, and NOT(NULL) is not true, so a
     * permanently blocked room silently looked available.
     */
    public function isBlockedDuring(string $from, string $to): bool
    {
        if ($this->blocked_from === null) {
            return false;
        }

        $blockedTo = $this->blocked_to?->format('Y-m-d') ?? '9999-12-31';

        return $this->blocked_from->format('Y-m-d') < $to && $from < $blockedTo;
    }

    /**
     * Unavailable for any reason — permanent status or a date-ranged block.
     */
    public function isUnavailableDuring(string $from, string $to): bool
    {
        return ! $this->status->isAllottable() || $this->isBlockedDuring($from, $to);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', RoomStatus::ACTIVE->value);
    }

    public function scopeOfType(Builder $q, int $roomTypeId): Builder
    {
        return $q->where('room_type_id', $roomTypeId);
    }

    public function label(): string
    {
        return $this->room_number;
    }
}
