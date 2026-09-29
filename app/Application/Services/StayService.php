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
use App\Models\StayExtension;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The stay lifecycle — WORKFLOW.md T12, T14, T15, T16, T17, T18.
 *
 * Two rules here are easy to get wrong and both are enforced:
 *
 * 1. CHECK-IN REQUIRES A COMPLETE ALLOTMENT (decision 9). A request holding only
 *    some of its rooms cannot check in — otherwise a party of six arrives to find
 *    two of three rooms ready and somebody sleeps in a corridor.
 *
 * 2. AN EXTENSION MUST RE-VERIFY AVAILABILITY. The extra nights are on the SAME
 *    room, and somebody else may already hold it. Extending without checking is
 *    the easiest way to double-book a guest house.
 */
class StayService
{
    public function __construct(
        private readonly AvailabilityQueryInterface $availability,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
    ) {}

    // ------------------------------------------------------------------ T12

    /**
     * Check the party in. All rooms on the request are occupied at once.
     *
     * @throws InvalidTransitionException|RuntimeException
     */
    public function checkIn(BookingRequest $request, User $admin): BookingRequest
    {
        $this->assertAdmin($admin);

        // Decision 9. The state machine also refuses this, but a plain message
        // here tells the administrator what to do about it.
        if ($request->status === RequestStatus::PARTIALLY_ALLOTTED) {
            throw new RuntimeException(
                'This request is only partly allotted. Allot the remaining rooms before checking the party in.'
            );
        }

        $allotments = $this->liveAllotments($request);

        if ($allotments->isEmpty()) {
            throw new RuntimeException('No rooms are held for this request.');
        }

        // Arriving early would mean occupying a room the previous guest may still
        // hold, since availability was computed for the requested window only.
        if (today()->lt($request->check_in_date)) {
            throw new RuntimeException(sprintf(
                'Check-in opens on %s.',
                $request->check_in_date->format('d/m/Y'),
            ));
        }

        $this->assertCapacity($request, $allotments);

        return DB::transaction(function () use ($request, $admin, $allotments) {
            foreach ($allotments as $allotment) {
                $allotment->status = AllotmentStatus::CHECKED_IN;
                $allotment->actual_check_in_at = now();
                $allotment->checked_in_by = $admin->id;
                $allotment->save();
            }

            $this->transition($request, RequestAction::CHECK_IN, $admin, metadata: [
                'rooms' => $allotments->pluck('room.room_number')->all(),
            ]);

            return $request->refresh();
        });
    }

    // ------------------------------------------------------------ T14 and T15

    /**
     * Check out. Chooses T14 or T15 from the date, so an early departure is
     * recorded as such and the rooms are released immediately.
     *
     * @throws InvalidTransitionException
     */
    public function checkOut(BookingRequest $request, User $admin): BookingRequest
    {
        $this->assertAdmin($admin);

        $allotments = $this->liveAllotments($request);

        if ($allotments->isEmpty()) {
            throw new RuntimeException('No occupied rooms found for this request.');
        }

        $isEarly = today()->lt($request->check_out_date);

        $action = $isEarly ? RequestAction::EARLY_CHECKOUT : RequestAction::CHECK_OUT;

        $finalStatus = $isEarly
            ? AllotmentStatus::EARLY_CHECKOUT
            : AllotmentStatus::CHECKED_OUT;

        return DB::transaction(function () use ($request, $admin, $allotments, $action, $finalStatus, $isEarly) {
            foreach ($allotments as $allotment) {
                $allotment->status = $finalStatus;
                $allotment->actual_check_out_at = now();
                $allotment->checked_out_by = $admin->id;

                // On an early departure the charge is recomputed on nights actually
                // stayed, anchored on the real arrival time (REPORTS.md section 3).
                if ($isEarly) {
                    $nights = $allotment->nightsStayed();
                    $allotment->total_amount = bcmul((string) $allotment->rate_per_night, (string) $nights, 2);
                }

                $allotment->save();
            }

            $this->transition($request, $action, $admin, metadata: [
                'rooms' => $allotments->pluck('room.room_number')->all(),
                'early' => $isEarly,
            ]);

            return $request->refresh();
        });
    }

    // ------------------------------------------------------------------ T16

    /**
     * Guest asks to stay longer.
     *
     * Availability is NOT checked here. The applicant is not entitled to see it
     * (Core Rule 2), so the request is simply recorded and the Administration
     * decides. Telling the guest "no rooms" at this point would leak inventory.
     *
     * @throws InvalidTransitionException|RuntimeException
     */
    public function requestExtension(
        BookingRequest $request,
        User $actor,
        string $newCheckOutDate,
        ?string $reason = null,
    ): StayExtension {
        $allotments = $this->liveAllotments($request);

        if ($allotments->isEmpty()) {
            throw new RuntimeException('There is no active stay to extend.');
        }

        $current = $request->check_out_date->format('Y-m-d');

        if ($newCheckOutDate <= $current) {
            throw new RuntimeException('The new check-out date must be later than the current one.');
        }

        if (StayExtension::where('booking_request_id', $request->id)->pending()->exists()) {
            throw new RuntimeException('An extension request is already awaiting a decision.');
        }

        return DB::transaction(function () use ($request, $actor, $allotments, $current, $newCheckOutDate, $reason) {
            $extension = new StayExtension([
                'requested_check_out_date' => $newCheckOutDate,
                'reason' => $reason,
            ]);

            $extension->booking_request_id = $request->id;
            $extension->allotment_id = $allotments->first()->id;
            $extension->previous_check_out_date = $current;
            $extension->status = 'REQUESTED';
            $extension->requested_by = $actor->id;
            $extension->save();

            $this->transition($request, RequestAction::REQUEST_EXTENSION, $actor, remarks: $reason, metadata: [
                'from' => $current,
                'to' => $newCheckOutDate,
            ]);

            return $extension;
        });
    }

    // ------------------------------------------------------------ T17 and T18

    /**
     * Approve an extension — but only if every held room is genuinely free for
     * the additional nights.
     *
     * @throws InvalidTransitionException|RuntimeException
     */
    public function approveExtension(StayExtension $extension, User $admin): StayExtension
    {
        $this->assertAdmin($admin);
        $this->assertPending($extension);

        $request = $extension->bookingRequest;
        $allotments = $this->liveAllotments($request);

        $from = $extension->previous_check_out_date->format('Y-m-d');
        $to = $extension->requested_check_out_date->format('Y-m-d');

        return DB::transaction(function () use ($extension, $admin, $request, $allotments, $from, $to) {

            // THE CHECK THAT MATTERS. Each room is locked, then re-verified for the
            // extra nights only — ignoring its own allotment, which of course
            // occupies the room up to the old checkout date.
            foreach ($allotments as $allotment) {
                Allotment::whereKey($allotment->id)->lockForUpdate()->first();

                $free = $this->availability->isStillFree(
                    roomId: $allotment->room_id,
                    from: $from,
                    to: $to,
                    ignoreAllotmentId: $allotment->id,
                );

                if (! $free) {
                    throw new RuntimeException(sprintf(
                        'Room %s is already booked for %s to %s, so the stay cannot be extended. '
                        .'Deny the request, or move the guest to another room first.',
                        $allotment->room->room_number,
                        Carbon::parse($from)->format('d/m/Y'),
                        Carbon::parse($to)->format('d/m/Y'),
                    ));
                }
            }

            // All clear: move the checkout date and re-price.
            foreach ($allotments as $allotment) {
                $allotment->check_out_date = $to;
                $nights = $allotment->nights();
                $allotment->total_amount = bcmul((string) $allotment->rate_per_night, (string) max(1, $nights), 2);
                $allotment->save();
            }

            $request->check_out_date = $to;
            $request->nights = (int) $request->check_in_date->startOfDay()
                ->diffInDays(Carbon::parse($to)->startOfDay());

            $extension->status = 'APPROVED';
            $extension->decided_by = $admin->id;
            $extension->decided_at = now();
            $extension->save();

            $this->transition($request, RequestAction::APPROVE_EXTENSION, $admin, metadata: [
                'new_check_out' => $to,
                'additional_nights' => $extension->additionalNights(),
            ]);

            return $extension->refresh();
        });
    }

    /**
     * T18 — deny. The original dates stand.
     *
     * @throws InvalidTransitionException
     */
    public function denyExtension(StayExtension $extension, User $admin, string $reason): StayExtension
    {
        $this->assertAdmin($admin);
        $this->assertPending($extension);

        $request = $extension->bookingRequest;

        return DB::transaction(function () use ($extension, $admin, $request, $reason) {
            $extension->status = 'DENIED';
            $extension->denial_reason = $reason;
            $extension->decided_by = $admin->id;
            $extension->decided_at = now();
            $extension->save();

            $this->transition($request, RequestAction::DENY_EXTENSION, $admin, remarks: $reason);

            return $extension->refresh();
        });
    }

    // ---------------------------------------------------------------- QR check-in

    /**
     * Resolve a QR token to its allotment for the check-in desk.
     */
    public function findByQrToken(string $token): ?Allotment
    {
        return Allotment::query()
            ->with(['bookingRequest.requester', 'room.roomType'])
            ->where('qr_token', $token)
            ->first();
    }

    // ---------------------------------------------------------------- internals

    /**
     * @return Collection<int, Allotment>
     */
    private function liveAllotments(BookingRequest $request)
    {
        return Allotment::query()
            ->with('room.roomType')
            ->where('booking_request_id', $request->id)
            ->occupying()
            ->orderBy('id')
            ->get();
    }

    /**
     * Nobody may be housed beyond a room's capacity — a fire-safety matter as
     * much as a comfort one.
     *
     * @param  Collection<int, Allotment>  $allotments
     */
    private function assertCapacity(BookingRequest $request, $allotments): void
    {
        foreach ($allotments as $allotment) {
            $capacity = $allotment->room->effectiveCapacity();

            if ($allotment->occupants_count > $capacity) {
                throw new RuntimeException(sprintf(
                    'Room %s holds %d, but %d occupants are assigned to it.',
                    $allotment->room->room_number,
                    $capacity,
                    $allotment->occupants_count,
                ));
            }
        }

        $totalCapacity = $allotments->sum(fn (Allotment $a) => $a->room->effectiveCapacity());

        if ($request->total_members > $totalCapacity) {
            throw new RuntimeException(sprintf(
                'The allotted rooms hold %d people in total, but the request is for %d. '
                .'Allot another room before checking in.',
                $totalCapacity,
                $request->total_members,
            ));
        }
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
        ?string $remarks = null,
        array $metadata = [],
    ): void {
        $from = $request->status;

        $to = RequestStateMachine::assert(
            from: $from,
            action: $action,
            actor: $actor->roleSlug(),
            isOwner: $request->isOwnedBy($actor),
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

        // After commit only — see ApprovalService for why.
        $event = match ($action) {
            RequestAction::CHECK_IN => NotificationEvent::STAY_CHECKED_IN,
            RequestAction::CHECK_OUT => NotificationEvent::STAY_CHECKED_OUT,
            RequestAction::EARLY_CHECKOUT => NotificationEvent::STAY_EARLY_CHECKOUT,
            RequestAction::REQUEST_EXTENSION => NotificationEvent::STAY_EXTENSION_REQUESTED,
            RequestAction::APPROVE_EXTENSION => NotificationEvent::STAY_EXTENDED,
            RequestAction::DENY_EXTENSION => NotificationEvent::STAY_EXTENSION_DENIED,
            default => null,
        };

        if ($event !== null) {
            DB::afterCommit(fn () => $this->notifications->dispatch($event, $request->fresh()));
        }
    }

    private function assertAdmin(User $actor): void
    {
        if (! $actor->isAdmin()) {
            throw new RuntimeException('Only the Administration may manage check-in and check-out.');
        }
    }

    private function assertPending(StayExtension $extension): void
    {
        if (! $extension->isPending()) {
            throw new RuntimeException('This extension request has already been decided.');
        }
    }
}
