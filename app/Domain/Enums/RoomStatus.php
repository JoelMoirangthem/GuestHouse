<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * Operational state of a physical room — SCHEMA.md section 9.
 *
 * Supplies the "Blocked" column in the inventory view (infographic section 7).
 * A room can be out of service in two different ways, and both must be honoured
 * by the availability query:
 *
 *   - permanently, via this status
 *   - for a date range, via rooms.blocked_from / blocked_to
 */
enum RoomStatus: string
{
    case ACTIVE = 'ACTIVE';
    case BLOCKED = 'BLOCKED';
    case MAINTENANCE = 'MAINTENANCE';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'In service',
            self::BLOCKED => 'Blocked',
            self::MAINTENANCE => 'Under maintenance',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::ACTIVE => 'bg-[--color-success-bg] border-green-200 text-[--color-success]',
            self::BLOCKED => 'bg-[--color-danger-bg] border-red-200 text-[--color-danger]',
            self::MAINTENANCE => 'bg-[--color-pending-bg] border-amber-200 text-[--color-pending]',
        };
    }

    /**
     * Only an ACTIVE room can ever be allotted. Both other states count toward
     * the Blocked column in the inventory report.
     */
    public function isAllottable(): bool
    {
        return $this === self::ACTIVE;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
