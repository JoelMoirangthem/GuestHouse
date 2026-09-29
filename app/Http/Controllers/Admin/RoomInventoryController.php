<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\AvailabilityService;
use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Room Inventory — infographic section 7.
 *
 * Admin only, with a date-range picker. Managers and the ADG are promised
 * read-only inventory in the specification, but a DATE-RANGE availability search
 * is precisely what Core Rule 2 withholds from them; their view (Phase 8) shows
 * current-state counts without a date picker.
 */
class RoomInventoryController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
    ) {}

    public function index(Request $request): View
    {
        $from = (string) ($request->query('from') ?: now()->format('Y-m-d'));
        $to = (string) ($request->query('to') ?: now()->addDay()->format('Y-m-d'));

        // Guard against a hand-edited query string producing an inverted window.
        if ($to <= $from) {
            $to = Carbon::parse($from)->addDay()->format('Y-m-d');
        }

        $summary = $this->availability->summary($from, $to, $request->user());

        return view('admin.inventory.index', [
            'summary' => $summary,
            'from' => $from,
            'to' => $to,
            'totals' => [
                'total' => array_sum(array_column($summary, 'total')),
                'available' => array_sum(array_column($summary, 'available')),
                'booked' => array_sum(array_column($summary, 'booked')),
                'blocked' => array_sum(array_column($summary, 'blocked')),
            ],
            'blockedRooms' => Room::query()
                ->with('roomType')
                ->where(fn ($q) => $q
                    ->whereIn('status', ['BLOCKED', 'MAINTENANCE'])
                    ->orWhereNotNull('blocked_from'))
                ->orderBy('room_number')
                ->get(),
        ]);
    }
}
