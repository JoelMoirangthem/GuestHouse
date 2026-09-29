<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Application\Services\ApprovalService;
use App\Application\Services\AuditLogger;
use App\Application\Services\AvailabilityService;
use App\Domain\Enums\RequestStatus;
use App\Domain\StateMachine\InvalidTransitionException;
use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Manager review — SCREENS.md 3.4.
 *
 * Three actions: approve, reject, request more information. Approve may also
 * hold rooms chosen from the room board (PLAN.md decision 10).
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
        private readonly AvailabilityService $availability,
    ) {}

    public function index(Request $request): View
    {
        $manager = $request->user();

        // Requests routed to this manager, either explicitly or through the
        // reporting line.
        $queue = BookingRequest::query()
            ->awaitingManager()
            ->where(fn ($q) => $q
                ->where('manager_id', $manager->id)
                ->orWhereHas('requester', fn ($r) => $r->where('reporting_manager_id', $manager->id)))
            ->with(['requester', 'occupants'])
            ->orderBy('check_in_date')
            ->paginate(15);

        $waiting = BookingRequest::query()
            ->where('status', RequestStatus::MORE_INFO_MANAGER->value)
            ->where('manager_id', $manager->id)
            ->with('requester')
            ->orderByDesc('more_info_at')
            ->get();

        return view('manager.requests.index', [
            'queue' => $queue,
            'waiting' => $waiting,
            'counts' => $this->approvals->queueCounts(),
        ]);
    }

    public function show(Request $request, BookingRequest $bookingRequest): View
    {
        $this->authorize('view', $bookingRequest);

        $bookingRequest->load(['occupants', 'documents', 'requester.reportingManager', 'hostEmployee']);

        // The room board is offered only while this Manager can still act on
        // the request; the service enforces that, so any refusal just means
        // "no board", not an error page.
        try {
            $board = $this->availability->roomBoardForReview($bookingRequest, $request->user());
        } catch (AuthorizationException|\RuntimeException) {
            $board = null;
        }

        return view('manager.requests.show', [
            'request' => $bookingRequest,
            'history' => $this->audit->historyFor($bookingRequest),
            'board' => $board,
        ]);
    }

    public function approve(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
            'room_ids' => ['nullable', 'array', 'max:20'],
            'room_ids.*' => ['integer', 'distinct', 'exists:rooms,id'],
        ]);

        $roomIds = array_map('intval', $validated['room_ids'] ?? []);

        try {
            $bookingRequest = $this->approvals->managerApprove(
                $bookingRequest,
                $request->user(),
                $validated['remarks'] ?? null,
                $roomIds,
            );
        } catch (InvalidTransitionException|\RuntimeException $e) {
            // Most likely a room taken by someone else a moment ago. The page
            // reloads with a fresh board and the Manager's remarks intact.
            return back()->withInput()->withErrors(['action' => $e->getMessage()]);
        }

        $message = $roomIds === []
            ? "{$bookingRequest->request_no} approved and sent to the Administration for room allotment."
            : sprintf('%s approved with %d %s allotted.',
                $bookingRequest->request_no, count($roomIds), Str::plural('room', count($roomIds)));

        return redirect()
            ->route('manager.requests.index')
            ->with('success', $message);
    }

    public function reject(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        // Remarks are required, not optional: an applicant is entitled to know
        // why their request was refused.
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'remarks.required' => 'Please record the reason for rejection.',
            'remarks.min' => 'Please give a slightly fuller reason.',
        ]);

        try {
            $this->approvals->managerReject($bookingRequest, $request->user(), $validated['remarks']);
        } catch (InvalidTransitionException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()
            ->route('manager.requests.index')
            ->with('success', "{$bookingRequest->request_no} has been rejected.");
    }

    public function moreInfo(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'remarks.required' => 'Describe what information the applicant should provide.',
        ]);

        try {
            $this->approvals->managerRequestInfo($bookingRequest, $request->user(), $validated['remarks']);
        } catch (InvalidTransitionException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()
            ->route('manager.requests.index')
            ->with('success', "Information requested from the applicant for {$bookingRequest->request_no}.");
    }
}
