<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Contracts\AvailabilityQueryInterface;
use App\Models\BookingRequest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The only way to ask "what is free?" — and it answers nobody but an
 * administrator, and only once a request has cleared both approval stages.
 *
 * CORE RULES 1 AND 2 LIVE HERE.
 *
 *   Rule 1: availability is checked only AFTER Manager and ADG approval.
 *   Rule 2: users never see availability at all.
 *
 * The guards are repeated here even though the route is already behind
 * role:admin. A service that is safe only because of who happens to call it is
 * not safe — the next caller may be a console command or a queued job.
 */
class AvailabilityService
{
    public function __construct(
        private readonly AvailabilityQueryInterface $query,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Availability summary for screen 3.3, for a specific approved request.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws AuthorizationException if the actor is not an administrator
     * @throws RuntimeException if the request has not been fully approved
     */
    public function summaryForRequest(
        BookingRequest $request,
        User $actor,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $this->assertAdmin($actor);
        $this->assertApproved($request);

        $from ??= $request->check_in_date->format('Y-m-d');
        $to ??= $request->check_out_date->format('Y-m-d');

        $this->assertValidWindow($from, $to);

        $summary = $this->query->summary($from, $to);

        $this->stampChecked($request, $actor, $from, $to);

        return $summary;
    }

    /**
     * Concrete rooms free for the window, for the allotment screen.
     *
     * @return Collection<int, Room>
     */
    public function availableRoomsForRequest(
        BookingRequest $request,
        User $actor,
        ?string $from = null,
        ?string $to = null,
        ?int $roomTypeId = null,
    ) {
        $this->assertAdmin($actor);
        $this->assertApproved($request);

        $from ??= $request->check_in_date->format('Y-m-d');
        $to ??= $request->check_out_date->format('Y-m-d');

        $this->assertValidWindow($from, $to);

        return $this->query->availableRooms($from, $to, $roomTypeId);
    }

    /**
     * A bare summary with no request context, for the inventory screen.
     *
     * Still administrator-only: a date-range availability search is exactly what
     * Core Rule 2 withholds from everyone else. Managers and the ADG get
     * current-state counts through a different, dateless view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summary(string $from, string $to, User $actor): array
    {
        $this->assertAdmin($actor);
        $this->assertValidWindow($from, $to);

        return $this->query->summary($from, $to);
    }

    /**
     * Current-state inventory for management — REPORTS.md section 4:
     * "Manager and ADG get current-state counts only, with no date-range search".
     *
     * The window is fixed to tonight (today → tomorrow) and deliberately takes
     * NO date arguments, so no caller can turn this into the date-range
     * availability search that Core Rule 2 reserves for the Administration.
     *
     * @return array<int, array<string, mixed>>
     */
    public function currentSnapshot(User $actor): array
    {
        if (! $actor->hasPermission('inventory.view')) {
            throw new AuthorizationException('You are not permitted to view the room inventory.');
        }

        return $this->query->summary(today()->format('Y-m-d'), today()->addDay()->format('Y-m-d'));
    }

    // ---------------------------------------------------------------- guards

    /**
     * The room board a Manager sees while reviewing a request — PLAN.md
     * decision 10, the one deliberate exception to Core Rule 1.
     *
     * Deliberately narrow: only the assigned Manager, only while the request is
     * PENDING_MANAGER, and only for that request's own dates. There is no date
     * argument, so this cannot become a general availability search.
     *
     * Every active-type room is classified into exactly one state, in priority
     * order: offline (out of service, date-blocked or retired type), booked
     * (held by a live allotment over these dates), available.
     *
     * @return array{
     *     from: string, to: string,
     *     counts: array{available: int, booked: int, offline: int},
     *     blocks: array<int, array{name: string, counts: array{available: int, booked: int, offline: int},
     *         floors: array<int, array{label: string, rooms: array<int, array<string, mixed>>}>}>
     * }
     *
     * @throws AuthorizationException|RuntimeException
     */
    public function roomBoardForReview(BookingRequest $request, User $actor): array
    {
        $this->assertReviewingManager($request, $actor);

        $from = $request->check_in_date->format('Y-m-d');
        $to = $request->check_out_date->format('Y-m-d');

        $freeIds = $this->query->availableRooms($from, $to)->pluck('id')->flip();

        // Only blocks that are in service appear (config gh.room_board_blocks).
        $visible = config('gh.room_board_blocks', []);

        $rooms = Room::query()
            ->with('roomType')
            ->whereIn('block', array_keys($visible))
            ->orderBy('block')
            ->orderByDesc('floor')
            ->orderBy('room_number')
            ->get();

        $zero = ['available' => 0, 'booked' => 0, 'offline' => 0];
        $counts = $zero;
        $blocks = [];

        foreach ($rooms as $room) {
            $state = match (true) {
                ! $room->roomType->is_active || $room->isUnavailableDuring($from, $to) => 'offline',
                $freeIds->has($room->id) => 'available',
                default => 'booked',
            };

            $counts[$state]++;

            $blockKey = $room->block;
            $floorKey = $room->floor ?? 0;

            $blocks[$blockKey] ??= [
                'name' => sprintf('Block %s (%s)', $blockKey, $visible[$blockKey]),
                'counts' => $zero,
                'floors' => [],
            ];
            $blocks[$blockKey]['counts'][$state]++;

            $blocks[$blockKey]['floors'][$floorKey] ??= [
                'label' => $floorKey === 0 ? 'VIP Suites' : 'Floor '.$floorKey,
                'rooms' => [],
            ];

            $blocks[$blockKey]['floors'][$floorKey]['rooms'][] = [
                'id' => $room->id,
                'number' => $room->room_number,
                'type_id' => $room->room_type_id,
                'type' => $room->roomType->displayName(),
                'capacity' => $room->effectiveCapacity(),
                'state' => $state,
                // A block reason is operational, not personal, so it is safe to
                // show. Who holds a booked room is deliberately NOT exposed.
                'note' => $state === 'offline' ? ($room->block_reason ?: $room->status->label()) : null,
            ];
        }

        foreach ($blocks as &$block) {
            krsort($block['floors']);
            $block['floors'] = array_values($block['floors']);
        }
        unset($block);

        return [
            'from' => $from,
            'to' => $to,
            'counts' => $counts,
            'blocks' => array_values($blocks),
        ];
    }

    /**
     * @throws AuthorizationException|RuntimeException
     */
    private function assertReviewingManager(BookingRequest $request, User $actor): void
    {
        $isManager = $actor->roleSlug() === \App\Domain\Enums\RoleSlug::MANAGER;

        if (! $isManager || ! $actor->hasPermission('room.allot.review')) {
            throw new AuthorizationException('Only the reviewing Manager may see the room board.');
        }

        $request->loadMissing('requester');

        if ($request->manager_id !== $actor->id && $request->requester->reporting_manager_id !== $actor->id) {
            throw new AuthorizationException('This request is not assigned to you.');
        }

        if ($request->status !== \App\Domain\Enums\RequestStatus::PENDING_MANAGER) {
            throw new RuntimeException('Rooms can be chosen only while the request awaits your review.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function assertAdmin(User $actor): void
    {
        if (! $actor->isAdmin() || ! $actor->hasPermission('availability.check')) {
            throw new AuthorizationException(
                'Room availability may be checked by the Administration only.'
            );
        }
    }

    /**
     * @throws RuntimeException
     */
    private function assertApproved(BookingRequest $request): void
    {
        if (! $request->status->allowsAvailabilityCheck()) {
            throw new RuntimeException(sprintf(
                'Availability cannot be checked while the request is "%s". '
                .'It must be approved by the Manager and the ADG first.',
                $request->status->label(),
            ));
        }
    }

    /**
     * @throws RuntimeException
     */
    private function assertValidWindow(string $from, string $to): void
    {
        if ($to <= $from) {
            throw new RuntimeException('The check-out date must be later than the check-in date.');
        }
    }

    /**
     * Stamp the first availability check on the request.
     *
     * This is the audit evidence that Core Rule 1 was honoured: the timestamp can
     * be compared against adg_acted_at to prove the check came after approval.
     * Only the first check is stamped, so a re-search does not overwrite it.
     */
    private function stampChecked(BookingRequest $request, User $actor, string $from, string $to): void
    {
        if ($request->availability_checked_at !== null) {
            return;
        }

        $request->availability_checked_at = now();
        $request->availability_checked_by = $actor->id;
        $request->saveQuietly();

        $this->audit->record(
            subject: $request,
            action: 'AVAILABILITY_CHECKED',
            actor: $actor,
            metadata: ['from' => $from, 'to' => $to],
        );
    }
}
