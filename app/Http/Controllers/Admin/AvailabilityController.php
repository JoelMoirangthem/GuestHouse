<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\AvailabilityService;
use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin — Check Availability, screen 3.3.
 *
 * The only place in the application where availability is visible, and the route
 * refuses outright unless the request has cleared both approval stages.
 */
class AvailabilityController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
    ) {}

    public function show(Request $request, BookingRequest $bookingRequest): View
    {
        // 409 Conflict rather than 403: the administrator is permitted to do this,
        // just not yet. The status of the record is what is wrong, not the actor.
        abort_unless(
            $bookingRequest->status->allowsAvailabilityCheck(),
            409,
            'This request has not completed the approval chain, so availability cannot be checked yet.'
        );

        $from = (string) ($request->query('from') ?: $bookingRequest->check_in_date->format('Y-m-d'));
        $to = (string) ($request->query('to') ?: $bookingRequest->check_out_date->format('Y-m-d'));

        try {
            $summary = $this->availability->summaryForRequest($bookingRequest, $request->user(), $from, $to);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        $bookingRequest->load(['requester', 'occupants', 'hostEmployee']);

        return view('admin.availability.show', [
            'request' => $bookingRequest,
            'summary' => $summary,
            'from' => $from,
            'to' => $to,
            'totals' => [
                'total' => array_sum(array_column($summary, 'total')),
                'available' => array_sum(array_column($summary, 'available')),
                'booked' => array_sum(array_column($summary, 'booked')),
                'blocked' => array_sum(array_column($summary, 'blocked')),
            ],
        ]);
    }
}
