<?php

declare(strict_types=1);

namespace App\Infrastructure\Queries;

use App\Domain\Contracts\AvailabilityQueryInterface;
use App\Domain\Enums\AllotmentStatus;
use App\Domain\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA.md section 14, implemented verbatim.
 *
 * Two rules govern everything in this class:
 *
 * 1. OVERLAP IS HALF-OPEN.
 *        existing.check_in < :to  AND  :from < existing.check_out
 *    A stay ending on the 22nd does not collide with one starting on the 22nd.
 *
 * 2. AN OPEN-ENDED BLOCK NEVER EVAPORATES.
 *    rooms.blocked_to may be NULL. A bare comparison against NULL yields NULL,
 *    and NOT(NULL) is not TRUE, so the room would appear available. Every
 *    comparison below uses COALESCE(blocked_to, '9999-12-31').
 *
 * Both rules are regression-tested; both were real defects found while auditing
 * the specification, before any of this was written.
 */
class SqlAvailabilityQuery implements AvailabilityQueryInterface
{
    private const FAR_FUTURE = '9999-12-31';

    /**
     * SQL fragment: this room has no overlapping live allotment.
     */
    private function noOverlapClause(string $roomAlias = 'r'): string
    {
        $occupying = "'".implode("','", AllotmentStatus::occupyingValues())."'";

        return "NOT EXISTS (
            SELECT 1 FROM allotments a
            WHERE a.room_id = {$roomAlias}.id
              AND a.status IN ({$occupying})
              AND a.check_in_date < :to
              AND :from < a.check_out_date
        )";
    }

    /**
     * SQL fragment: this room is not blocked across the window.
     */
    private function notBlockedClause(string $roomAlias = 'r'): string
    {
        return "NOT (
            {$roomAlias}.blocked_from IS NOT NULL
            AND {$roomAlias}.blocked_from < :to2
            AND :from2 < COALESCE({$roomAlias}.blocked_to, '".self::FAR_FUTURE."')
        )";
    }

    /**
     * Per-room-type counts.
     *
     * Each room is classified into EXACTLY ONE bucket, in priority order:
     *
     *   blocked   — out of service, or date-blocked across the window
     *   booked    — in service but already held for these dates
     *   available — everything else
     *
     * Classifying in PHP rather than with three SUM(CASE ...) expressions is
     * deliberate. It makes the invariant available + booked + blocked = total
     * true by construction, which REPORTS.md section 4 requires and which three
     * independent SQL sums can silently violate. The cost is irrelevant: this
     * guest house has around sixty rooms, and the query is still one round trip.
     */
    public function summary(string $from, string $to): array
    {
        $occupying = AllotmentStatus::occupyingValues();

        $rooms = Room::query()
            ->select('rooms.*')
            ->selectRaw(
                'EXISTS (
                    SELECT 1 FROM allotments a
                    WHERE a.room_id = rooms.id
                      AND a.status IN (?, ?)
                      AND a.check_in_date < ?
                      AND ? < a.check_out_date
                 ) AS has_clash',
                [$occupying[0], $occupying[1], $to, $from]
            )
            ->with('roomType')
            ->get();

        $types = RoomType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $out = [];

        foreach ($types as $type) {
            $ofType = $rooms->where('room_type_id', $type->id);

            $available = 0;
            $booked = 0;
            $blocked = 0;

            foreach ($ofType as $room) {
                if ($room->isUnavailableDuring($from, $to)) {
                    $blocked++;
                } elseif ((bool) $room->has_clash) {
                    $booked++;
                } else {
                    $available++;
                }
            }

            $out[] = [
                'room_type_id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'category' => $type->category,
                'capacity' => $type->default_capacity,
                'total' => $ofType->count(),
                'available' => $available,
                'booked' => $booked,
                'blocked' => $blocked,
            ];
        }

        return $out;
    }

    public function lockCandidates(int $roomTypeId, string $from, string $to, int $limit): array
    {
        $active = RoomStatus::ACTIVE->value;

        // The overlap and blocked predicates are INSIDE this WHERE, before LIMIT.
        // See the class docblock and AvailabilityQueryInterface for why.
        $sql = "
            SELECT r.id
            FROM rooms r
            WHERE r.room_type_id = :type
              AND r.status = '{$active}'
              AND {$this->notBlockedClause('r')}
              AND {$this->noOverlapClause('r')}
            ORDER BY r.room_number
            LIMIT {$limit}
            FOR UPDATE
        ";

        $rows = DB::select($sql, [
            'type' => $roomTypeId,
            'from' => $from, 'to' => $to,
            'from2' => $from, 'to2' => $to,
        ]);

        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    public function isStillFree(int $roomId, string $from, string $to, ?int $ignoreAllotmentId = null): bool
    {
        $room = Room::find($roomId);

        if ($room === null || $room->isUnavailableDuring($from, $to)) {
            return false;
        }

        $clash = DB::table('allotments')
            ->where('room_id', $roomId)
            ->whereIn('status', AllotmentStatus::occupyingValues())
            ->where('check_in_date', '<', $to)
            ->where('check_out_date', '>', $from)
            ->when($ignoreAllotmentId !== null, fn ($q) => $q->where('id', '!=', $ignoreAllotmentId))
            ->exists();

        return ! $clash;
    }

    public function availableRooms(string $from, string $to, ?int $roomTypeId = null): Collection
    {
        $occupying = AllotmentStatus::occupyingValues();

        return Room::query()
            ->with('roomType')
            ->active()
            ->when($roomTypeId !== null, fn ($q) => $q->where('room_type_id', $roomTypeId))
            // Exclude date-ranged blocks, treating a NULL blocked_to as open-ended.
            ->whereRaw(
                'NOT (blocked_from IS NOT NULL AND blocked_from < ? AND ? < COALESCE(blocked_to, ?))',
                [$to, $from, self::FAR_FUTURE]
            )
            ->whereDoesntHave('allotments', fn ($q) => $q
                ->whereIn('status', $occupying)
                ->where('check_in_date', '<', $to)
                ->where('check_out_date', '>', $from))
            ->orderBy('room_number')
            ->get();
    }
}
