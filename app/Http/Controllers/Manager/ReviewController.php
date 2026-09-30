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
        // One Manager decides every booking, so the queue is every request
        // awaiting a decision, regardless of the applicant's reporting line —
        // including the Manager's own bookings (e.g. a VIP reservation).
        $queue = BookingRequest::query()
            ->awaitingManager()
            ->with(['requester', 'occupants', 'hostEmployee'])
            ->orderBy('check_in_date')
            ->paginate(15);

        $waiting = BookingRequest::query()
            ->where('status', RequestStatus::MORE_INFO_MANAGER->value)
            ->with(['requester', 'occupants'])
            ->orderByDesc('more_info_at')
            ->get();

        return view('manager.requests.index', [
            'queue' => $queue,
            'waiting' => $waiting,
            'counts' => $this->approvals->queueCounts(),
            ...$this->decisionHistory($request),
        ]);
    }

    /**
     * Requests this Manager has already decided — approved or rejected.
     *
     * Both decisions stamp manager_id and manager_acted_at; asking for more
     * information does not, so a paused request never appears here. The
     * decision is derived from the status: REJECTED_MANAGER is a rejection,
     * anything else that carries the stamp was approved (and may since have
     * moved on to allotment, check-in, cancellation and so on).
     *
     * @return array<string, mixed>
     */
    private function decisionHistory(Request $request): array
    {
        $filter = in_array($request->query('decision'), ['approved', 'rejected'], true)
            ? $request->query('decision')
            : 'all';

        $base = BookingRequest::query()
            ->where('manager_id', $request->user()->id)
            ->whereNotNull('manager_acted_at');

        $rejected = RequestStatus::REJECTED_MANAGER->value;

        $history = (clone $base)
            ->when($filter === 'approved', fn ($q) => $q->where('status', '!=', $rejected))
            ->when($filter === 'rejected', fn ($q) => $q->where('status', $rejected))
            ->with(['requester', 'occupants', 'hostEmployee'])
            ->orderByDesc('manager_acted_at')
            ->paginate(15, pageName: 'history_page')
            ->withQueryString()
            ->fragment('history');

        return [
            'history' => $history,
            'historyFilter' => $filter,
            'historyCounts' => [
                'all' => (clone $base)->count(),
                'approved' => (clone $base)->where('status', '!=', $rejected)->count(),
                'rejected' => (clone $base)->where('status', $rejected)->count(),
            ],
        ];
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
            'room_ids' => ['required', 'array', 'min:1', 'max:20'],
            'room_ids.*' => ['integer', 'distinct', 'exists:rooms,id'],
        ], [
            'room_ids.required' => 'Select at least one room on the room board before approving.',
            'room_ids.min' => 'Select at least one room on the room board before approving.',
        ]);

        $roomIds = array_map('intval', $validated['room_ids']);

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

        $message = sprintf('%s approved with %d %s allotted.',
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
