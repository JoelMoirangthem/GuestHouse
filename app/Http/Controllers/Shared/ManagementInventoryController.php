<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shared;

use App\Application\Services\AvailabilityService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only room inventory for the Manager and the ADG — ROUTES.md
 * `/manager/inventory` and `/adg/inventory`, REPORTS.md section 4.
 *
 * Tonight's counts only. Any from/to in the query string is ignored: the
 * service method takes no dates, so a hand-edited URL cannot become a
 * date-range availability search (Core Rule 2).
 */
class ManagementInventoryController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
    ) {}

    public function index(Request $request): View
    {
        $summary = $this->availability->currentSnapshot($request->user());

        return view('shared.inventory', [
            'summary' => $summary,
            'asOf' => now(),
            'totals' => [
                'total' => array_sum(array_column($summary, 'total')),
                'available' => array_sum(array_column($summary, 'available')),
                'booked' => array_sum(array_column($summary, 'booked')),
                'blocked' => array_sum(array_column($summary, 'blocked')),
            ],
        ]);
    }
}
