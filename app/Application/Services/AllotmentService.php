<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Contracts\AvailabilityQueryInterface;
use App\Domain\Enums\AllotmentStatus;
use App\Domain\Enums\NotificationEvent;
use App\Domain\Enums\RequestAction;
use App\Domain\Enums\RequestStatus;
use App\Domain\StateMachine\InvalidTransitionException;
use App\Domain\StateMachine\RequestStateMachine;
use App\Models\Allotment;
use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Assigns rooms to an approved request — WORKFLOW.md T8, T9, T10, T11.
 *
 * THE DOUBLE-BOOKING DEFENCE, in four layers:
 *
 *   1. everything happens inside one database transaction
 *   2. candidate rooms are locked with SELECT ... FOR UPDATE, and the overlap
 *      predicate is inside that query's WHERE clause
 *   3. after the lock is held, each room's availability is re-verified
 *   4. a unique index on (room_id, occupies) rejects a duplicate even if the
 *      first three are somehow bypassed
 *
 * Layers 2 and 3 are not redundant. The in-WHERE filter picks the right
 * candidates; the post-lock re-check confirms nothing changed in between. MySQL
 * has no exclusion constraints, so none of this is optional.
 */
class AllotmentService
{
    public function __construct(
        private readonly AvailabilityQueryInterface $query,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * Allot specific rooms chosen by the administrator.
     *
     * @param  array<int, int>  $roomIds
     *
     * @throws InvalidTransitionException|RuntimeException
     */
    public function allot(BookingRequest $request, User $admin, array $roomIds): BookingRequest
    {
        $this->assertAdmin($admin);
        $this->assertAllottable($request);

        if ($roomIds === []) {
            throw new RuntimeException(
                'Select at least one room, or mark the request as no room available.'
            );
        }

        $from = $request->check_in_date->format('Y-m-d');
        $to = $request->check_out_date->format('Y-m-d');

        return DB::transaction(function () use ($request, $admin, $roomIds, $from, $to) {

            $rooms = $this->lockAndCreate($request, $admin, $roomIds, $from, $to);

            $totalHeld = $this->occupyingCount($request);

            // T8 versus T9 — the state machine decides which, from the counts.
            $target = RequestStateMachine::allotmentTarget($totalHeld, (int) $request->rooms_needed);

            $this->transition($request, RequestAction::ALLOT, $admin, $target, [
                'rooms' => $rooms->pluck('room_number')->all(),
                'rooms_held' => $totalHeld,
                'rooms_needed' => (int) $request->rooms_needed,
            ]);

            return $request->refresh();
        });
    }

    /**
     * Hold rooms chosen by the Manager at review — PLAN.md decision 10.
     *
     * Uses exactly the same four-layer defence as allot(): the rooms are taken
     * out of the pool now, so two Managers cannot pick the same room while
     * their requests wait for the ADG. The request's status is NOT changed
     * here; the caller (ApprovalService::managerApprove) moves it to
     * PENDING_ADG inside the same transaction.
     *
     * @param  array<int, int>  $roomIds
     * @return array<int, string> room numbers held
     *
     * @throws RuntimeException
     */
    public function holdAtReview(BookingRequest $request, User $manager, array $roomIds): array
    {
        if (! $manager->hasPermission('room.allot.review')) {
            throw new RuntimeException('You are not permitted to select rooms.');
        }

        if ($request->status !== RequestStatus::PENDING_MANAGER) {
            throw new RuntimeException('Rooms can be selected only while the request awaits Manager review.');
        }

        // Only rooms the board can show may be held — a hidden block (not yet in
        // service) is refused even from a crafted POST.
        $visibleBlocks = array_keys(config('gh.room_board_blocks', []));
        $outside = Room::query()->whereIn('id', $roomIds)
            ->where(fn ($q) => $q->whereNotIn('block', $visibleBlocks)->orWhereNull('block'))
            ->pluck('room_number');

        if ($outside->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Room %s is not available for selection.', $outside->implode(', '),
            ));
        }

        $from = $request->check_in_date->format('Y-m-d');
        $to = $request->check_out_date->format('Y-m-d');

        return DB::transaction(function () use ($request, $manager, $roomIds, $from, $to) {
            $numbers = $this->lockAndCreate($request, $manager, $roomIds, $from, $to)
                ->pluck('room_number')->all();

            $this->audit->record(
                subject: $request,
                action: 'ROOMS_HELD',
                actor: $manager,
                metadata: ['rooms' => $numbers, 'rooms_needed' => (int) $request->rooms_needed],
            );

            return $numbers;
        });
    }

    /**
     * Release every room a request still holds without changing its status.
     *
     * Used when a request leaves the approval chain (ADG rejection, or the
     * applicant withdrawing while it is PENDING_ADG) so Manager-held rooms
     * return to the pool. The caller owns the status transition.
     */
    public function releaseAllHeld(BookingRequest $request, User $actor, string $reason): int
    {
        $held = Allotment::query()
            ->where('booking_request_id', $request->id)
            ->where('status', AllotmentStatus::ALLOTTED->value)
            ->with('room')
            ->get();

        if ($held->isEmpty()) {
            return 0;
        }

        foreach ($held as $allotment) {
            $allotment->status = AllotmentStatus::CANCELLED;
            $allotment->cancel_reason = $reason;
            $allotment->save();
        }

        $this->audit->record(
            subject: $request,
            action: 'ROOMS_RELEASED',
            actor: $actor,
            remarks: $reason,
            metadata: ['rooms' => $held->pluck('room.room_number')->all()],
        );

        return $held->count();
    }

    /**
     * Rooms currently held (not yet checked in) for a request.
     */
    public function heldRoomCount(BookingRequest $request): int
    {
        return $this->occupyingCount($request);
    }

    /**
     * Layers 2-4 of the double-booking defence, shared by allot() and
     * holdAtReview(). Must be called inside a transaction.
     *
     * @param  array<int, int>  $roomIds
     * @return \Illuminate\Support\Collection<int, Room>
     */
    private function lockAndCreate(BookingRequest $request, User $actor, array $roomIds, string $from, string $to)
    {
        // Layer 2 — lock the chosen rooms in a deterministic order.
        // Ordering by id prevents two users picking overlapping sets in
        // opposite orders from deadlocking each other.
        $rooms = Room::query()
            ->with('roomType')
            ->whereIn('id', $roomIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($rooms->count() !== count(array_unique($roomIds))) {
            throw new RuntimeException('One or more of the selected rooms no longer exists.');
        }

        foreach ($rooms as $room) {
            // Layer 3 — re-verify now that the row is locked. This is the
            // assertion that actually defeats the race.
            if (! $this->query->isStillFree($room->id, $from, $to)) {
                throw new RuntimeException(sprintf(
                    'Room %s was just taken by another booking. '
                    .'Please re-check availability and try again.',
                    $room->room_number,
                ));
            }

            if ($room->isUnavailableDuring($from, $to)) {
                throw new RuntimeException(
                    "Room {$room->room_number} is blocked for these dates."
                );
            }

            // A retired room type is hidden from every availability search,
            // so a room of that type can only arrive here via a crafted POST.
            if (! $room->roomType->is_active) {
                throw new RuntimeException(
                    "Room {$room->room_number} belongs to a retired room type and cannot be allotted."
                );
            }

            $this->createAllotment($request, $room, $actor, $from, $to);
        }

        return $rooms;
    }

    /**
     * T10 — approved, but nothing free.
     *
     * @throws InvalidTransitionException
     */
    public function markNoRoomAvailable(BookingRequest $request, User $admin, string $reason): BookingRequest
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($request, $admin, $reason) {
            $this->transition($request, RequestAction::MARK_NO_ROOM, $admin, null, [], $reason);

            return $request->refresh();
        });
    }

    /**
     * T20 — look again later.
     *
     * @throws InvalidTransitionException
     */
    public function recheck(BookingRequest $request, User $admin): BookingRequest
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($request, $admin) {
            $this->transition($request, RequestAction::RECHECK_AVAILABILITY, $admin, null);

            return $request->refresh();
        });
    }

    /**
     * Release one room, returning it to the pool.
     *
     * The request steps back from ALLOTTED to PARTIALLY_ALLOTTED when this drops
     * it below the required count — otherwise the administrator would believe the
     * booking was still complete.
     */
    public function release(Allotment $allotment, User $admin, ?string $reason = null): BookingRequest
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($allotment, $admin, $reason) {
            $request = $allotment->bookingRequest;

            if ($allotment->status === AllotmentStatus::CHECKED_IN) {
                throw new RuntimeException(
                    'This room is occupied. Check the guest out instead of releasing it.'
                );
            }

            $allotment->status = AllotmentStatus::CANCELLED;
            $allotment->cancel_reason = $reason;
            $allotment->save();

            $held = $this->occupyingCount($request);
            $from = $request->status;

            // A Manager-held room released while the request still waits for the
            // ADG must not advance the request past its approval stage.
            if ($request->status !== RequestStatus::PENDING_ADG) {
                $request->status = $held === 0
                    ? RequestStatus::PENDING_ALLOTMENT
                    : ($held >= (int) $request->rooms_needed ? RequestStatus::ALLOTTED : RequestStatus::PARTIALLY_ALLOTTED);
            }

            $request->save();

            $this->audit->record(
                subject: $request,
                action: 'ROOM_RELEASED',
                actor: $admin,
                from: $from,
                to: $request->status,
                remarks: $reason,
                metadata: ['room' => $allotment->room->room_number, 'rooms_held' => $held],
            );

            return $request->refresh();
        });
    }

    // ---------------------------------------------------------------- internals

    private function createAllotment(
        BookingRequest $request,
        Room $room,
        User $admin,
        string $from,
        string $to,
    ): Allotment {
        $nights = (int) $request->nights;

        // Snapshot the rate. A later tariff revision must not rewrite history.
        $rate = Tariff::resolveFor($room->room_type_id, $request->purpose, $request->check_in_date);

        $allotment = new Allotment([
            'room_id' => $room->id,
            'check_in_date' => $from,
            'check_out_date' => $to,
            'occupants_count' => min($room->effectiveCapacity(), (int) $request->total_members),
        ]);

        $allotment->booking_request_id = $request->id;
        $allotment->allotment_no = Allotment::nextAllotmentNo();
        $allotment->status = AllotmentStatus::ALLOTTED;
        $allotment->rate_per_night = $rate;
        $allotment->total_amount = bcmul($rate, (string) max(1, $nights), 2);
        $allotment->allotted_by = $admin->id;
        $allotment->allotted_at = now();
        $allotment->qr_token = Allotment::freshQrToken();

        try {
            $allotment->save();
        } catch (QueryException $e) {
            // Layer 4 — the unique index caught a duplicate the earlier layers
            // missed. Translated into the same message the user would have seen
            // from layer 3, rather than surfacing a raw SQL error.
            if ($this->isDuplicateKey($e)) {
                throw new RuntimeException(sprintf(
                    'Room %s was just taken by another booking. '
                    .'Please re-check availability and try again.',
                    $room->room_number,
                ));
            }

            throw $e;
        }

        return $allotment;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            || str_contains($e->getMessage(), 'uq_room_occupies');
    }

    private function occupyingCount(BookingRequest $request): int
    {
        return Allotment::query()
            ->where('booking_request_id', $request->id)
            ->occupying()
            ->count();
    }

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidTransitionException
     */
    private function transition(
        BookingRequest $request,
        RequestAction $action,
        User $actor,
        ?RequestStatus $target,
        array $metadata = [],
        ?string $remarks = null,
    ): void {
        $from = $request->status;

        $to = RequestStateMachine::assert(
            from: $from,
            action: $action,
            actor: $actor->roleSlug(),
            to: $target,
            remarks: $remarks,
        );

        $request->status = $to;
        $request->save();

        $this->audit->record(
            subject: $request,
            action: $action->auditAction(),
            actor: $actor,
            from: $from,
            to: $to,
            remarks: $remarks,
            metadata: $metadata,
        );

        // After commit only: a notification failure must never undo an allotment
        // that has already taken a room out of the pool.
        $event = match ($action) {
            RequestAction::ALLOT => NotificationEvent::ROOMS_ALLOTTED,
            RequestAction::MARK_NO_ROOM => NotificationEvent::ROOMS_NONE_AVAILABLE,
            RequestAction::RECHECK_AVAILABILITY => NotificationEvent::AVAILABILITY_RECHECK,
            default => null,
        };

        if ($event !== null) {
            DB::afterCommit(fn () => $this->notifications->dispatch(
                $event,
                $request->fresh(),
                ['rooms' => implode(', ', $metadata['rooms'] ?? [])],
            ));
        }
    }

    private function assertAdmin(User $actor): void
    {
        if (! $actor->isAdmin()) {
            throw new RuntimeException('Only the Administration may allot rooms.');
        }
    }

    private function assertAllottable(BookingRequest $request): void
    {
        if (! $request->status->allowsAvailabilityCheck()) {
            throw new RuntimeException(sprintf(
                'Rooms cannot be allotted while the request is "%s".',
                $request->status->label(),
            ));
        }
    }
}
