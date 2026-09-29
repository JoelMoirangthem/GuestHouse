<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Enums\NotificationEvent;
use App\Domain\Enums\RequestAction;
use App\Domain\Enums\RequestStatus;
use App\Domain\StateMachine\InvalidTransitionException;
use App\Domain\StateMachine\RequestStateMachine;
use App\Models\BookingRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Manager and ADG decisions — WORKFLOW.md T2, T3, T4, T6, T7.
 *
 * Every method follows the same shape: ask the state machine whether the
 * transition is legal, then apply it inside a transaction, then record the audit
 * entry. The state machine is never bypassed, so the approval chain cannot be
 * short-circuited by a new code path.
 *
 * Note what is NOT here: there is no adgRequestInfo() method. The ADG may
 * approve or reject only (PLAN.md decision 5).
 */
class ApprovalService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
        private readonly AllotmentService $allotments,
    ) {}

    /**
     * Map an action to the notification event it announces.
     */
    private function eventFor(RequestAction $action): ?NotificationEvent
    {
        return match ($action) {
            RequestAction::MANAGER_APPROVE => NotificationEvent::REQUEST_APPROVED_MANAGER,
            RequestAction::MANAGER_REJECT,
            RequestAction::ADG_REJECT => NotificationEvent::REQUEST_REJECTED,
            RequestAction::MANAGER_REQUEST_INFO => NotificationEvent::REQUEST_MORE_INFO,
            RequestAction::ADG_APPROVE => NotificationEvent::REQUEST_APPROVED_ADG,
            default => null,
        };
    }

    // ------------------------------------------------------------------ manager

    /**
     * T2 — Manager approves. This is the only approval required, so the request
     * moves straight to the Administration for room allotment.
     *
     * When the Manager also picks rooms (PLAN.md decision 10) they are held and
     * then immediately confirmed as the allotment in the same transaction, so
     * either the whole approval-and-allotment happens or none of it does. An
     * empty $roomIds leaves allotment entirely to the Administration.
     *
     * @param  array<int, int>  $roomIds
     *
     * @throws InvalidTransitionException|\RuntimeException
     */
    public function managerApprove(
        BookingRequest $request,
        User $manager,
        ?string $remarks = null,
        array $roomIds = [],
    ): BookingRequest {
        $this->assertIsTheAssignedManager($request, $manager);

        return DB::transaction(function () use ($request, $manager, $remarks, $roomIds) {
            if ($roomIds !== []) {
                $this->allotments->holdAtReview($request, $manager, $roomIds);
            }

            $request = $this->apply(
                $request,
                RequestAction::MANAGER_APPROVE,
                $manager,
                $remarks,
                function (BookingRequest $r) use ($manager, $remarks): void {
                    $r->manager_id = $manager->id;
                    $r->manager_acted_at = now();
                    $r->manager_remarks = $remarks;
                },
            );

            // Rooms the Manager held become the allotment immediately, since the
            // Manager's approval is final. Without any held rooms the request
            // simply waits in the Administration's allotment queue.
            $held = $this->allotments->heldRoomCount($request);

            if ($held > 0) {
                $this->confirmHeldRooms($request, $manager, $held);
            }

            return $request->refresh();
        });
    }

    /**
     * T3 — Manager rejects. Terminal; remarks are mandatory.
     *
     * @throws InvalidTransitionException
     */
    public function managerReject(BookingRequest $request, User $manager, string $remarks): BookingRequest
    {
        $this->assertIsTheAssignedManager($request, $manager);

        return $this->apply(
            $request,
            RequestAction::MANAGER_REJECT,
            $manager,
            $remarks,
            function (BookingRequest $r) use ($manager, $remarks): void {
                $r->manager_id = $manager->id;
                $r->manager_acted_at = now();
                $r->manager_remarks = $remarks;
            },
        );
    }

    /**
     * T4 — Manager asks the applicant for more information.
     *
     * @throws InvalidTransitionException
     */
    public function managerRequestInfo(BookingRequest $request, User $manager, string $note): BookingRequest
    {
        $this->assertIsTheAssignedManager($request, $manager);

        return $this->apply(
            $request,
            RequestAction::MANAGER_REQUEST_INFO,
            $manager,
            $note,
            function (BookingRequest $r) use ($manager, $note): void {
                $r->manager_id = $manager->id;
                $r->more_info_note = $note;
                $r->more_info_at = now();
                // manager_acted_at is deliberately NOT set: the Manager has not
                // decided yet, only paused. Setting it would make the progress
                // tracker claim the review stage was complete.
            },
        );
    }

    // ---------------------------------------------------------------------- ADG

    /**
     * T6 — ADG approves. The request becomes visible to the Administration for
     * an availability check, and only now.
     *
     * @throws InvalidTransitionException
     */
    public function adgApprove(BookingRequest $request, User $adg, ?string $remarks = null): BookingRequest
    {
        return DB::transaction(function () use ($request, $adg, $remarks) {
            $request = $this->apply(
                $request,
                RequestAction::ADG_APPROVE,
                $adg,
                $remarks,
                function (BookingRequest $r) use ($adg, $remarks): void {
                    $r->adg_id = $adg->id;
                    $r->adg_acted_at = now();
                    $r->adg_remarks = $remarks;
                },
            );

            // T21 — rooms the Manager held become the allotment.
            $held = $this->allotments->heldRoomCount($request);

            if ($held > 0) {
                $this->confirmHeldRooms($request, $adg, $held);
            }

            return $request->refresh();
        });
    }

    /**
     * Confirm rooms already held for the request as its allotment. Called by the
     * actor who finalises the approval (now the Manager; historically the ADG).
     *
     * @throws InvalidTransitionException
     */
    private function confirmHeldRooms(BookingRequest $request, User $actor, int $held): void
    {
        $from = $request->status;

        $to = RequestStateMachine::assert(
            from: $from,
            action: RequestAction::CONFIRM_ALLOTMENT,
            actor: $actor->roleSlug(),
            to: RequestStateMachine::allotmentTarget($held, (int) $request->rooms_needed),
        );

        $request->status = $to;
        $request->save();

        $rooms = $request->allotments()->occupying()->with('room')->get()
            ->pluck('room.room_number')->all();

        $this->audit->record(
            subject: $request,
            action: RequestAction::CONFIRM_ALLOTMENT->auditAction(),
            actor: $actor,
            from: $from,
            to: $to,
            metadata: ['rooms' => $rooms, 'rooms_held' => $held, 'rooms_needed' => (int) $request->rooms_needed],
        );

        DB::afterCommit(fn () => $this->notifications->dispatch(
            NotificationEvent::ROOMS_ALLOTTED,
            $request->fresh(),
            ['rooms' => implode(', ', $rooms)],
        ));
    }

    /**
     * T7 — ADG rejects. Terminal; remarks are mandatory.
     *
     * @throws InvalidTransitionException
     */
    public function adgReject(BookingRequest $request, User $adg, string $remarks): BookingRequest
    {
        return DB::transaction(function () use ($request, $adg, $remarks) {
            $request = $this->apply(
                $request,
                RequestAction::ADG_REJECT,
                $adg,
                $remarks,
                function (BookingRequest $r) use ($adg, $remarks): void {
                    $r->adg_id = $adg->id;
                    $r->adg_acted_at = now();
                    $r->adg_remarks = $remarks;
                },
            );

            // Rooms the Manager held go back to the pool.
            $this->allotments->releaseAllHeld($request, $adg, 'Request rejected by the ADG');

            return $request;
        });
    }

    // ---------------------------------------------------------------- internals

    /**
     * Shared transition pipeline: validate, mutate, persist, audit.
     *
     * @param  callable(BookingRequest): void  $mutate
     *
     * @throws InvalidTransitionException
     */
    private function apply(
        BookingRequest $request,
        RequestAction $action,
        User $actor,
        ?string $remarks,
        callable $mutate,
    ): BookingRequest {
        $from = $request->status;

        // The state machine is the authority. If it refuses, nothing is written.
        $to = RequestStateMachine::assert(
            from: $from,
            action: $action,
            actor: $actor->roleSlug(),
            isOwner: $request->isOwnedBy($actor),
            remarks: $remarks,
        );

        return DB::transaction(function () use ($request, $action, $actor, $remarks, $mutate, $from, $to) {
            $mutate($request);

            $request->status = $to;
            $request->save();

            $this->audit->record(
                subject: $request,
                action: $action->auditAction(),
                actor: $actor,
                from: $from,
                to: $to,
                remarks: $remarks,
            );

            /*
             * Notifications go out only once the transaction has committed.
             *
             * If they were dispatched inline, a mail-server timeout would raise
             * inside the transaction and roll back an approval that the Manager
             * had legitimately given. The decision is the important thing; the
             * email is a courtesy.
             */
            if ($event = $this->eventFor($action)) {
                DB::afterCommit(fn () => $this->notifications->dispatch($event, $request->fresh()));
            }

            return $request->refresh();
        });
    }

    /**
     * A manager may only act on requests routed to them.
     *
     * Without this, any manager in the institute could approve any employee's
     * request, which defeats the purpose of having a reporting line. An
     * administrator is exempt so they can unblock a request when a manager is
     * unavailable — and that action is audited like any other.
     *
     * @throws InvalidTransitionException
     */
    private function assertIsTheAssignedManager(BookingRequest $request, User $manager): void
    {
        if ($manager->isAdmin()) {
            return;
        }

        $isAssigned = $request->manager_id === $manager->id;
        $isReportee = $request->requester->reporting_manager_id === $manager->id;

        if (! $isAssigned && ! $isReportee) {
            throw new InvalidTransitionException(
                'This request is not assigned to you. Only the applicant\'s reporting manager may act on it.'
            );
        }
    }

    /**
     * Queue counts for the dashboards.
     *
     * @return array<string, int>
     */
    public function queueCounts(): array
    {
        return [
            'pending_manager' => BookingRequest::query()->awaitingManager()->count(),
            'more_info' => BookingRequest::query()
                ->where('status', RequestStatus::MORE_INFO_MANAGER->value)->count(),
            'pending_adg' => BookingRequest::query()->awaitingAdg()->count(),
            'awaiting_allotment' => BookingRequest::query()->awaitingAllotment()->count(),
        ];
    }
}
