<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Enums\AllotmentStatus;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoomStatus;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Notification;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Figures for the admin dashboard: tiles, daily occupancy and the room-type
 * split. All queries are read-only.
 *
 * PERIOD SEMANTICS
 *   - a request belongs to the period of its submitted_at date
 *   - occupancy counts the nights that fall inside [from, to]
 *   - queue tiles (Pending Manager / ADG / Allotment) are CURRENT counts, not
 *     period counts: a queue is "what is waiting now"
 *
 * EARLY CHECKOUT CORRECTION. A night is occupied while `day < check_out_date`.
 * After an early departure check_out_date still holds the SCHEDULED date, but
 * the room was released and may already be re-allotted, so the literal formula
 * would count the same room twice. For EARLY_CHECKOUT rows the stay therefore
 * ends on the actual departure date, never earlier than one night.
 */
class DashboardService
{
    /** Allotment statuses that represent a room actually given to a guest. */
    private const HELD = ['ALLOTTED', 'CHECKED_IN', 'CHECKED_OUT', 'EARLY_CHECKOUT'];

    /**
     * @return array<string, int>
     */
    public function dashboardTiles(string $from, string $to): array
    {
        [$fromAt, $toAt] = $this->dayBounds($from, $to);

        return [
            'total' => BookingRequest::query()
                ->where('status', '!=', RequestStatus::DRAFT->value)
                ->whereBetween('submitted_at', [$fromAt, $toAt])
                ->count(),
            'pending_manager' => BookingRequest::query()
                ->whereIn('status', [RequestStatus::PENDING_MANAGER->value, RequestStatus::MORE_INFO_MANAGER->value])
                ->count(),
            'pending_adg' => BookingRequest::where('status', RequestStatus::PENDING_ADG->value)->count(),
            'awaiting_allotment' => BookingRequest::query()->awaitingAllotment()->count(),
            // Rooms, not requests: one request can hold several (PLAN.md decision 1).
            'rooms_allotted' => Allotment::query()
                ->whereIn('status', self::HELD)
                ->whereBetween('allotted_at', [$fromAt, $toAt])
                ->count(),
            'failed_notifications' => Notification::where('status', 'FAILED')->count(),
        ];
    }

    /**
     * Occupancy per day.
     *
     * The denominator is ACTIVE rooms only and ignores date-ranged blocks, so it
     * is stable across the month and days are comparable.
     *
     * @return array<int, array{date: string, occupied: int, pct: float}>
     */
    public function occupancyByDay(string $from, string $to): array
    {
        $activeRooms = Room::where('status', RoomStatus::ACTIVE->value)->count();
        $days = $this->days($from, $to);
        $counts = array_fill_keys($days, 0);

        foreach ($this->heldAllotmentsOverlapping($from, $to) as $a) {
            foreach ($this->nightsOf($a) as $night) {
                if (isset($counts[$night])) {
                    $counts[$night]++;
                }
            }
        }

        return array_map(fn (string $d) => [
            'date' => $d,
            'occupied' => $counts[$d],
            'pct' => $activeRooms > 0 ? round($counts[$d] / $activeRooms * 100, 1) : 0.0,
        ], $days);
    }

    /**
     * Room-type split of allotments made in the period.
     *
     * @return array<int, array{name: string, count: int}>
     */
    public function allotmentsByRoomType(string $from, string $to): array
    {
        [$fromAt, $toAt] = $this->dayBounds($from, $to);

        $counts = Allotment::query()
            ->join('rooms', 'rooms.id', '=', 'allotments.room_id')
            ->whereIn('allotments.status', self::HELD)
            ->whereBetween('allotments.allotted_at', [$fromAt, $toAt])
            ->groupBy('rooms.room_type_id')
            ->selectRaw('rooms.room_type_id, COUNT(*) AS n')
            ->pluck('n', 'room_type_id');

        return RoomType::orderBy('sort_order')->get()
            ->map(fn (RoomType $t) => ['name' => $t->displayName(), 'count' => (int) ($counts[$t->id] ?? 0)])
            ->filter(fn ($r) => $r['count'] > 0)
            ->values()
            ->all();
    }

    // ================================================================ internals

    /** @return Collection<int, Allotment> */
    private function heldAllotmentsOverlapping(string $from, string $to): Collection
    {
        return Allotment::query()
            ->with('room')
            ->whereIn('status', self::HELD)
            ->where('check_in_date', '<=', $to)
            ->where('check_out_date', '>', $from)
            ->get();
    }

    /**
     * The nights (Y-m-d) an allotment occupied its room, half-open [in, out).
     *
     * @return array<int, string>
     */
    private function nightsOf(Allotment $a): array
    {
        $in = $a->check_in_date->copy()->startOfDay();
        $out = $a->check_out_date->copy()->startOfDay();

        if ($a->status === AllotmentStatus::EARLY_CHECKOUT && $a->actual_check_out_at !== null) {
            $actual = Carbon::parse($a->actual_check_out_at)->startOfDay();
            $out = $actual->max($in->copy()->addDay())->min($out);
        }

        $nights = [];
        for ($d = $in->copy(); $d->lt($out); $d->addDay()) {
            $nights[] = $d->format('Y-m-d');
        }

        return $nights;
    }

    /** @return array<int, string> every date in [from, to], inclusive */
    private function days(string $from, string $to): array
    {
        $out = [];
        for ($d = Carbon::parse($from); $d->format('Y-m-d') <= $to; $d->addDay()) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    /** @return array{0: string, 1: string} datetime bounds covering whole days */
    private function dayBounds(string $from, string $to): array
    {
        return [$from.' 00:00:00', $to.' 23:59:59'];
    }
}
