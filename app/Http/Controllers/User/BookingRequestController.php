<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Application\Services\BookingRequestService;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\VisitPurpose;
use App\Domain\StateMachine\InvalidTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequestRequest;
use App\Models\BookingRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The applicant's own requests.
 *
 * This controller contains no availability logic of any kind, and no route in
 * this area exposes room counts. Core Rule 2: users declare intent, they never
 * see inventory.
 */
class BookingRequestController extends Controller
{
    public function __construct(
        private readonly BookingRequestService $service,
    ) {}

    public function index(Request $request): View
    {
        $requests = BookingRequest::query()
            ->where('user_id', $request->user()->id)
            ->with('occupants')
            ->latest('id')
            ->paginate(10);

        return view('user.requests.index', [
            'requests' => $requests,
            'counts' => $this->summaryCounts($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', BookingRequest::class);

        return view('user.requests.create', [
            'purposes' => VisitPurpose::cases(),
            'hosts' => $this->hostCandidates($request->user()),
        ]);
    }

    public function store(StoreBookingRequestRequest $request): RedirectResponse
    {
        try {
            $booking = $this->service->createAndSubmit(
                requester: $request->user(),
                data: $request->safe()->except(['occupants', 'documents']),
                occupants: $request->input('occupants', []),
                files: $request->file('documents', []),
            );
        } catch (InvalidTransitionException $e) {
            // Business-rule failures are shown against the form rather than as a
            // 500, because they are the applicant's to fix.
            return back()->withInput()->withErrors(['submit' => $e->getMessage()]);
        }

        return redirect()
            ->route('my.requests.show', $booking)
            ->with('success', "Request {$booking->request_no} has been submitted for approval.");
    }

    public function show(Request $request, BookingRequest $bookingRequest): View
    {
        $this->authorize('view', $bookingRequest);

        $bookingRequest->load(['occupants', 'documents', 'requester', 'hostEmployee', 'manager', 'adg']);

        return view('user.requests.show', [
            'request' => $bookingRequest,
        ]);
    }

    public function edit(Request $request, BookingRequest $bookingRequest): View
    {
        $this->authorize('update', $bookingRequest);

        $bookingRequest->load(['occupants', 'documents']);

        return view('user.requests.edit', [
            'request' => $bookingRequest,
            'purposes' => VisitPurpose::cases(),
            'hosts' => $this->hostCandidates($request->user()),
        ]);
    }

    public function update(StoreBookingRequestRequest $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $this->authorize('update', $bookingRequest);

        try {
            $this->service->update(
                request: $bookingRequest,
                actor: $request->user(),
                data: $request->safe()->except(['occupants', 'documents']),
                occupants: $request->input('occupants', []),
                files: $request->file('documents', []),
            );
        } catch (InvalidTransitionException $e) {
            return back()->withInput()->withErrors(['submit' => $e->getMessage()]);
        }

        return redirect()
            ->route('my.requests.show', $bookingRequest)
            ->with('success', 'Your request has been updated.');
    }

    /**
     * Resubmit after the Manager asked for more information (T5).
     */
    public function resubmit(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $this->authorize('submit', $bookingRequest);

        try {
            $this->service->submit($bookingRequest, $request->user());
        } catch (InvalidTransitionException $e) {
            return back()->withErrors(['submit' => $e->getMessage()]);
        }

        return redirect()
            ->route('my.requests.show', $bookingRequest)
            ->with('success', 'Your request has been returned to the Manager for review.');
    }

    public function cancel(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $this->authorize('cancel', $bookingRequest);

        $validated = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->service->cancel(
                $bookingRequest,
                $request->user(),
                $validated['cancel_reason'] ?? null,
            );
        } catch (InvalidTransitionException $e) {
            return back()->withErrors(['submit' => $e->getMessage()]);
        }

        return redirect()
            ->route('my.requests.index')
            ->with('success', "Request {$bookingRequest->request_no} has been cancelled.");
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, int>
     */
    private function summaryCounts(User $user): array
    {
        $base = fn () => BookingRequest::query()->where('user_id', $user->id);

        return [
            'total' => $base()->submitted()->count(),
            'in_progress' => $base()->whereIn('status', [
                RequestStatus::PENDING_MANAGER->value,
                RequestStatus::MORE_INFO_MANAGER->value,
                RequestStatus::PENDING_ADG->value,
                RequestStatus::PENDING_ALLOTMENT->value,
            ])->count(),
            'allotted' => $base()->whereIn('status', [
                RequestStatus::ALLOTTED->value,
                RequestStatus::PARTIALLY_ALLOTTED->value,
                RequestStatus::CHECKED_IN->value,
            ])->count(),
            'completed' => $base()->whereIn('status', [
                RequestStatus::CHECKED_OUT->value,
                RequestStatus::EARLY_CHECKOUT->value,
            ])->count(),
        ];
    }

    /**
     * Employees who can host a guest. Excludes the applicant, since one cannot
     * be one's own host.
     *
     * @return Collection<int, User>
     */
    private function hostCandidates(User $user)
    {
        return User::query()
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->whereNotNull('employee_code')
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code', 'designation']);
    }
}
