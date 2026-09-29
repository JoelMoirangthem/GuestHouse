<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Enums\VisitPurpose;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tariff extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_type_id', 'purpose', 'amount_per_night',
        'effective_from', 'effective_to', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => VisitPurpose::class,
            'amount_per_night' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Resolve the rate for a room type, purpose and date.
     *
     * REPORTS.md section 3: the most specific purpose match wins, then the latest
     * effective_from that still covers the date. Returns 0.00 rather than null
     * when nothing matches, so an allotment can still be made — a missing tariff
     * is a configuration gap, not a reason to refuse an approved officer a room.
     */
    public static function resolveFor(
        int $roomTypeId,
        VisitPurpose $purpose,
        ?\DateTimeInterface $on = null,
    ): string {
        $date = ($on ?? now())->format('Y-m-d');

        $tariff = static::query()
            ->where('room_type_id', $roomTypeId)
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            // Purpose-specific rows sort before the catch-all NULL row.
            ->orderByRaw('CASE WHEN purpose = ? THEN 0 WHEN purpose IS NULL THEN 1 ELSE 2 END', [$purpose->value])
            ->orderByDesc('effective_from')
            ->first();

        return $tariff === null ? '0.00' : (string) $tariff->amount_per_night;
    }
}
