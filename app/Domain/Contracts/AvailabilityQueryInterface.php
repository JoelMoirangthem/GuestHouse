<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Models\Room;
use Illuminate\Support\Collection;

/**
 * The one query complex enough to justify isolating behind an interface
 * (PLAN.md decision 5).
 *
 * Everything else in this application uses Eloquent directly. This is abstracted
 * because it is the single place where a subtle mistake silently corrupts the
 * guest house's inventory, so it needs to be swappable and tested in isolation.
 */
interface AvailabilityQueryInterface
{
    /**
     * Per-room-type counts for the admin availability screen (3.3).
     *
     * Display only. Never trust these numbers when writing — they may be stale by
     * the time an allotment is attempted.
     *
     * @return array<int, array{
     *     room_type_id:int, code:string, name:string, category:string,
     *     capacity:int, total:int, available:int, booked:int, blocked:int
     * }>
     */
    public function summary(string $from, string $to): array;

    /**
     * Candidate room ids for allotment, locked FOR UPDATE.
     *
     * MUST be called inside a transaction. The overlap and blocked predicates
     * belong INSIDE the WHERE clause, before LIMIT: filtering only on room status
     * and then re-checking after the lock would lock the lowest-numbered rooms
     * whether or not they are free, and report no availability while other rooms
     * of the same type sit empty.
     *
     * @return array<int, int> room ids
     */
    public function lockCandidates(int $roomTypeId, string $from, string $to, int $limit): array;

    /**
     * Re-verify one room immediately after the lock is acquired.
     *
     * This is what actually defeats the race. The in-WHERE filter above selects
     * the right candidates; this confirms nothing changed between the read and
     * the lock. Both are required — they do different jobs.
     */
    public function isStillFree(int $roomId, string $from, string $to, ?int $ignoreAllotmentId = null): bool;

    /**
     * Rooms free for the window, for the admin's selection list.
     *
     * @return Collection<int, Room>
     */
    public function availableRooms(string $from, string $to, ?int $roomTypeId = null);
}
