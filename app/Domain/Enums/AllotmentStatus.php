<?php

declare(strict_types=1);

namespace App\Domain\Enums;

/**
 * State of a single room allotment — SCHEMA.md section 11.
 *
 * Distinct from RequestStatus: a request may hold several allotments, and one
 * room can be released while others are retained. Conflating the two is what
 * would have capped every booking at one room.
 */
enum AllotmentStatus: string
{
    case ALLOTTED = 'ALLOTTED';
    case CHECKED_IN = 'CHECKED_IN';
    case CHECKED_OUT = 'CHECKED_OUT';
    case EARLY_CHECKOUT = 'EARLY_CHECKOUT';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::ALLOTTED => 'Allotted',
            self::CHECKED_IN => 'Occupied',
            self::CHECKED_OUT => 'Vacated',
            self::EARLY_CHECKOUT => 'Vacated early',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::ALLOTTED => 'bg-[--color-success-bg] border-green-300 text-[--color-success]',
            self::CHECKED_IN => 'bg-[--color-success-bg] border-green-400 text-green-800',
            self::CHECKED_OUT,
            self::EARLY_CHECKOUT => 'bg-[--color-neutral-bg] border-[--color-line-strong] text-[--color-neutral]',
            self::CANCELLED => 'bg-[--color-danger-bg] border-red-200 text-[--color-danger]',
        };
    }

    /**
     * Whether this allotment still holds the room, and therefore blocks another
     * booking over the same dates.
     *
     * THIS IS THE DEFINITION THE AVAILABILITY QUERY DEPENDS ON. Only ALLOTTED
     * and CHECKED_IN occupy a room. A cancelled or vacated allotment must not
     * block anyone, or the guest house would slowly lose its entire inventory to
     * historical records.
     */
    public function occupiesRoom(): bool
    {
        return in_array($this, [self::ALLOTTED, self::CHECKED_IN], true);
    }

    /**
     * The two statuses used in every overlap predicate.
     *
     * @return array<int, string>
     */
    public static function occupyingValues(): array
    {
        return [self::ALLOTTED->value, self::CHECKED_IN->value];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
