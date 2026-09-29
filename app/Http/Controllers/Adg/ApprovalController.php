<?php

declare(strict_types=1);

namespace App\Http\Controllers\Adg;

use App\Application\Services\ApprovalService;
use App\Application\Services\AuditLogger;
use App\Domain\StateMachine\InvalidTransitionException;
use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ADG approval — SCREENS.md 3.4, ADG variant.
 *
 * TWO actions only: approve and reject.
 *
 * There is deliberately no moreInfo() method on this controller. PLAN.md
 * decision 5 restricts the ADG to approve or reject, and that restriction is
 * enforced in four independent places:
 *
 *   1. no route                (routes/web.php)
 *   2. no permission           (RolePermissionSeeder — request.moreinfo.adg absent)
 *   3. no state transition     (RequestStateMachine — no rule from PENDING_ADG)
 *   4. no controller method    (here)
 *
 * Any one of them would stop a crafted POST. All four are present so that
 * removing one by accident cannot silently open the path.
 */
class ApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $queue = BookingRequest::query()
            ->awaitingAdg()
            ->with(['requester', 'manager'])
            ->orderBy('check_in_date')
            ->paginate(15);

        return view('adg.requests.index', [
            'queue' => $queue,
            'counts' => $this->approvals->queueCounts(),
        ]);
    }

    public function show(Request $request, BookingRequest $bookingRequest): View
    {
        $this->authorize('view', $bookingRequest);

        $bookingRequest->load(['occupants', 'documents', 'requester', 'hostEmployee', 'manager']);

        return view('adg.requests.show', [
            'request' => $bookingRequest,
            'history' => $this->audit->historyFor($bookingRequest),
        ]);
    }

    public function approve(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->approvals->adgApprove(
                $bookingRequest,
                $request->user(),
                $validated['remarks'] ?? null,
            );
        } catch (InvalidTransitionException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()
            ->route('adg.requests.index')
            ->with('success', "{$bookingRequest->request_no} approved. The Administration will now check availability.");
    }

    public function reject(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'remarks.required' => 'Please record the reason for rejection.',
        ]);

        try {
            $this->approvals->adgReject($bookingRequest, $request->user(), $validated['remarks']);
        } catch (InvalidTransitionException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return redirect()
            ->route('adg.requests.index')
            ->with('success', "{$bookingRequest->request_no} has been rejected.");
    }
}
