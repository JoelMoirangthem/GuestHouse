<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\StayService;
use App\Domain\Enums\RequestStatus;
use App\Domain\StateMachine\InvalidTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\StayExtension;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Check-in, check-out and extension decisions.
 */
class StayController extends Controller
{
    public function __construct(
        private readonly StayService $stays,
    ) {}

    /**
     * Today's desk: arrivals due, guests in residence, departures due, and
     * extensions awaiting a decision.
     */
    public function index(Request $request): View
    {
        return view('admin.stays.index', [
            'arrivals' => BookingRequest::query()
                ->where('status', RequestStatus::ALLOTTED->value)
                ->whereDate('check_in_date', '<=', today())
                ->with('requester')
                ->orderBy('check_in_date')
                ->get(),

            'inResidence' => BookingRequest::query()
                ->whereIn('status', [RequestStatus::CHECKED_IN->value, RequestStatus::EXTENSION_REQUESTED->value])
                ->with('requester')
                ->orderBy('check_out_date')
                ->get(),

            'departures' => BookingRequest::query()
                ->where('status', RequestStatus::CHECKED_IN->value)
                ->whereDate('check_out_date', '<=', today())
                ->with('requester')
                ->orderBy('check_out_date')
                ->get(),

            'extensions' => StayExtension::query()
                ->pending()
                ->with(['bookingRequest.requester', 'allotment.room'])
                ->orderBy('created_at')
                ->get(),

            'upcoming' => BookingRequest::query()
                ->where('status', RequestStatus::ALLOTTED->value)
                ->whereDate('check_in_date', '>', today())
                ->with('requester')
                ->orderBy('check_in_date')
                ->limit(10)
                ->get(),
        ]);
    }

    public function checkIn(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        try {
            $this->stays->checkIn($bookingRequest, $request->user());
        } catch (InvalidTransitionException|\RuntimeException $e) {
            return back()->withErrors(['stay' => $e->getMessage()]);
        }

        return back()->with('success', "{$bookingRequest->request_no} checked in.");
    }

    public function checkOut(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        try {
            $result = $this->stays->checkOut($bookingRequest, $request->user());
        } catch (InvalidTransitionException|\RuntimeException $e) {
            return back()->withErrors(['stay' => $e->getMessage()]);
        }

        $early = $result->status === RequestStatus::EARLY_CHECKOUT;

        return back()->with('success', $early
            ? "{$bookingRequest->request_no} checked out early. Rooms released and the charge recomputed."
            : "{$bookingRequest->request_no} checked out.");
    }

    /**
     * QR check-in for the desk. The token identifies the allotment; the usual
     * guards still apply through checkIn().
     */
    public function qrCheckIn(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:40'],
        ]);

        $allotment = $this->stays->findByQrToken($validated['token']);

        if ($allotment === null) {
            return back()->withErrors(['stay' => 'That code does not match any allotment.']);
        }

        try {
            $this->stays->checkIn($allotment->bookingRequest, $request->user());
        } catch (InvalidTransitionException|\RuntimeException $e) {
            return back()->withErrors(['stay' => $e->getMessage()]);
        }

        return back()->with('success',
            "{$allotment->bookingRequest->request_no} checked in from the QR code.");
    }

    public function approveExtension(Request $request, StayExtension $extension): RedirectResponse
    {
        try {
            $this->stays->approveExtension($extension, $request->user());
        } catch (InvalidTransitionException|\RuntimeException $e) {
            // The availability refusal surfaces here.
            return back()->withErrors(['stay' => $e->getMessage()]);
        }

        return back()->with('success', 'Extension approved and the stay dates updated.');
    }

    public function denyExtension(Request $request, StayExtension $extension): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Record why the extension cannot be granted.',
        ]);

        try {
            $this->stays->denyExtension($extension, $request->user(), $validated['reason']);
        } catch (InvalidTransitionException|\RuntimeException $e) {
            return back()->withErrors(['stay' => $e->getMessage()]);
        }

        return back()->with('success', 'Extension denied. The original dates stand.');
    }
}
