<?php

declare(strict_types=1);

namespace App\Domain;

use App\Models\Room;
use App\Models\RoomType;

/**
 * rooms_needed = ceil(members / capacity) — SCHEMA.md section 14.
 *
 * Pure arithmetic kept out of the service so it can be unit-tested without a
 * database. The result is a suggestion: the administrator may allot more or
 * fewer at the discretion of the authority.
 */
final class RoomsNeeded
{
    /** The commonest configuration in this guest house, used when no type is chosen. */
    public const DEFAULT_CAPACITY = 2;

    public static function for(int $members, int $capacity = self::DEFAULT_CAPACITY): int
    {
        return max(1, (int) ceil(max(1, $members) / max(1, $capacity)));
    }

    /**
     * Effective capacity: a room-level override beats the type default
     * (SCHEMA.md: rooms.capacity NULL => use room_types.default_capacity).
     */
    public static function capacityOf(Room|RoomType|null $subject): int
    {
        return match (true) {
            $subject instanceof Room => $subject->effectiveCapacity(),
            $subject instanceof RoomType => (int) $subject->default_capacity,
            default => self::DEFAULT_CAPACITY,
        };
    }
}
