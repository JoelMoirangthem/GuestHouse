<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\AllotmentLetterService;
use App\Application\Services\AllotmentService;
use App\Application\Services\ApprovalService;
use App\Application\Services\AuditLogger;
use App\Application\Services\AvailabilityService;
use App\Domain\StateMachine\InvalidTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Allotment;
use App\Models\BookingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Admin — Room Allotment, screen 3.5, plus the administrator's work queue.
 */
class AllotmentController extends Controller
{
    public function __construct(
        private readonly AllotmentService $allotments,
        private readonly AvailabilityService $availability,
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
        private readonly AllotmentLetterService $letters,
    ) {}

    /**
     * The administrator's queue: approved requests needing a room decision.
     *
     * This screen is why the fifth dashboard tile exists — without it there is no
     * way to find requests waiting on the Administration.
     */
    public function index(Request $request): View
    {
        $queue = BookingRequest::query()
            ->awaitingAllotment()
            ->with(['requester', 'adg', 'occupants', 'hostEmployee'])
            ->orderBy('check_in_date')
            ->paginate(15);

        return view('admin.allotments.index', [
            'queue' => $queue,
            'counts' => $this->approvals->queueCounts(),
        ]);
    }

    public function create(Request $request, BookingRequest $bookingRequest): View
    {
        abort_unless(
            $bookingRequest->status->allowsAvailabilityCheck(),
            409,
            'This request has not completed the approval chain.'
        );

        $from = $bookingRequest->check_in_date->format('Y-m-d');
        $to = $bookingRequest->check_out_date->format('Y-m-d');

        $rooms = $this->availability->availableRoomsForRequest(
            $bookingRequest,
            $request->user(),
            $from,
            $to,
            $request->integer('room_type_id') ?: null,
        );

        $bookingRequest->load(['requester', 'occupants', 'hostEmployee']);

        return view('admin.allotments.create', [
            'request' => $bookingRequest,
            'rooms' => $rooms->groupBy(fn ($r) => $r->roomType->displayName()),
            'held' => Allotment::where('booking_request_id', $bookingRequest->id)
                ->occupying()->with('room.roomType')->get(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function store(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $validated = $request->validate([
            'room_ids' => ['required', 'array', 'min:1'],
            'room_ids.*' => ['integer', 'exists:rooms,id'],
        ], [
            'room_ids.required' => 'Select at least one room to allot.',
        ]);

        try {
            $bookingRequest = $this->allotments->allot(
                $bookingRequest,
                $request->user(),
                array_map('intval', $validated['room_ids']),
            );
        } catch (InvalidTransitionException|\RuntimeException $e) {
            // The concurrency message from AllotmentService surfaces here, sending
            // the administrator back to re-check availability.
            return back()->withErrors(['allot' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.allotments.index')
            ->with('success', "Rooms allotted for {$bookingRequest->request_no}. The applicant has been notified.");
    }

    public function markNoRoom(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Record why no room can be offered.',
        ]);

        try {
            $this->allotments->markNoRoomAvailable($bookingRequest, $request->user(), $validated['reason']);
        } catch (InvalidTransitionException|\RuntimeException $e) {
            return back()->withErrors(['allot' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.allotments.index')
            ->with('success', "{$bookingRequest->request_no} marked as no room available.");
    }

    public function recheck(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        try {
            $this->allotments->recheck($bookingRequest, $request->user());
        } catch (InvalidTransitionException|\RuntimeException $e) {
            return back()->withErrors(['allot' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.allotments.create', $bookingRequest)
            ->with('success', 'Request returned to the allotment queue.');
    }

    public function destroy(Request $request, Allotment $allotment): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->allotments->release($allotment, $request->user(), $validated['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['allot' => $e->getMessage()]);
        }

        return back()->with('success', "Room {$allotment->room->room_number} released.");
    }

    /**
     * Allotment letter with QR (ROUTES.md: /admin/allotments/{allotment}/letter).
     */
    public function letter(Request $request, Allotment $allotment): Response
    {
        $bookingRequest = $allotment->bookingRequest;

        abort_unless($this->letters->isIssuable($bookingRequest), 409,
            'The allotment letter is available once all rooms are allotted.');
        abort_unless($allotment->status->occupiesRoom(), 409, 'This room has been released.');

        $letter = $this->letters->render($bookingRequest, $allotment);

        return response($letter['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$letter['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function show(Request $request, BookingRequest $bookingRequest): View
    {
        $bookingRequest->load(['requester', 'occupants', 'documents', 'manager', 'adg']);

        return view('admin.allotments.show', [
            'request' => $bookingRequest,
            'allotments' => Allotment::where('booking_request_id', $bookingRequest->id)
                ->with('room.roomType')->orderBy('id')->get(),
            'history' => $this->audit->historyFor($bookingRequest),
        ]);
    }
}
