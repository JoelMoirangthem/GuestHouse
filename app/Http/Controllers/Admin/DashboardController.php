<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\ReportService;
use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Admin dashboard — infographic section 4, REPORTS.md sections 1 and 2.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
    ) {}

    public function index(Request $request): View
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = isset($data['month']) ? Carbon::createFromFormat('Y-m-d', $data['month'].'-01') : now()->startOfMonth();
        $from = $month->copy()->startOfMonth()->format('Y-m-d');
        $to = $month->copy()->endOfMonth()->format('Y-m-d');

        return view('admin.dashboard', [
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'tiles' => $this->reports->dashboardTiles($from, $to),
            'occupancy' => $this->reports->occupancyByDay($from, $to),
            'byType' => $this->reports->allotmentsByRoomType($from, $to),
            'queue' => BookingRequest::query()->awaitingAllotment()->with('requester')
                ->orderBy('check_in_date')->limit(5)->get(),
        ]);
    }
}
