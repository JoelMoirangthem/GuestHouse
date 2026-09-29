<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Enums\NotificationEvent;
use App\Domain\Enums\RequestAction;
use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\RoleSlug;
use App\Domain\Enums\VisitPurpose;
use App\Domain\RoomsNeeded;
use App\Domain\StateMachine\InvalidTransitionException;
use App\Domain\StateMachine\RequestStateMachine;
use App\Models\BookingRequest;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * All business logic for creating, editing and submitting a booking request.
 *
 * Controllers call into this class and nothing else. Keeping the rules here means
 * the same logic applies whether a request arrives from the web form, an artisan
 * command or a future API — and it can be tested without HTTP.
 */
class BookingRequestService
{
    public function __construct(
        private readonly DocumentStorageService $documents,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
        private readonly AllotmentService $allotments,
    ) {}

    /**
     * Create a request and immediately submit it for approval (T1).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $occupants
     * @param  array<int, UploadedFile>  $files
     * @param  int|null  $roomsRequested  rooms the applicant asked for; when null
     *                                    the count is estimated from the members
     * @param  string  $docType  document type recorded against every uploaded file
     * @param  bool  $requireIdentityProof  when false, the request may be submitted
     *                                       without an attached identity document
     */
    public function createAndSubmit(
        User $requester,
        array $data,
        array $occupants,
        array $files,
        ?int $roomsRequested = null,
        string $docType = 'AADHAAR',
        bool $requireIdentityProof = true,
    ): BookingRequest {
        return DB::transaction(function () use ($requester, $data, $occupants, $files, $roomsRequested, $docType, $requireIdentityProof) {
            $request = $this->buildDraft($requester, $data, $occupants, $files, $roomsRequested, $docType);

            return $this->submit($request, $requester, $requireIdentityProof);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $occupants
     * @param  array<int, UploadedFile>  $files
     */
    public function createDraft(User $requester, array $data, array $occupants, array $files): BookingRequest
    {
        return DB::transaction(
            fn () => $this->buildDraft($requester, $data, $occupants, $files)
        );
    }

    /**
     * Transition DRAFT or MORE_INFO_MANAGER into PENDING_MANAGER.
     *
     * Guard order matters: the business preconditions are checked before the
     * state machine is consulted, so the applicant gets a useful message rather
     * than a bare "invalid transition".
     *
     * @throws InvalidTransitionException
     */
    public function submit(BookingRequest $request, User $actor, bool $requireIdentityProof = true): BookingRequest
    {
        $this->assertSubmittable($request, $requireIdentityProof);

        $isResubmission = $request->status === RequestStatus::MORE_INFO_MANAGER;

        $action = $isResubmission ? RequestAction::RESUBMIT : RequestAction::SUBMIT;

        $target = RequestStateMachine::assert(
            from: $request->status,
            action: $action,
            actor: $actor->roleSlug(),
            isOwner: $request->isOwnedBy($actor),
        );

        return DB::transaction(function () use ($request, $target, $isResubmission, $action, $actor) {
            $from = $request->status;

            $request->status = $target;

            if ($isResubmission) {
                $request->resubmitted_at = now();
            } else {
                $request->submitted_at = now();
            }

            // Route to a Manager who can actually act on the request. Normally
            // that is the requester's reporting manager, but when the reporting
            // line points at a non-manager (for example a Manager or the ADG
            // raising their own booking, whose reporting manager is the ADG) the
            // request would otherwise land in a queue no Manager can open and be
            // invisible forever. resolveReviewingManagerId() guarantees a valid
            // Manager is chosen so the request always reaches the review queue.
            $request->manager_id = $this->resolveReviewingManagerId($request->requester);

            $request->save();

            $this->audit->record(
                subject: $request,
                action: $action->auditAction(),
                actor: $actor,
                from: $from,
                to: $target,
            );

            // The Manager needs to know there is something to review. Dispatched
            // after commit so a mail failure cannot lose the submission itself.
            $event = $isResubmission
                ? NotificationEvent::REQUEST_RESUBMITTED
                : NotificationEvent::REQUEST_SUBMITTED;

            DB::afterCommit(fn () => $this->notifications->dispatch($event, $request->fresh()));

            return $request->refresh();
        });
    }

    /**
     * Preconditions for T1 in WORKFLOW.md.
     *
     * @throws InvalidTransitionException
     */
    public function assertSubmittable(BookingRequest $request, bool $requireIdentityProof = true): void
    {
        $request->loadMissing(['occupants', 'documents', 'requester']);

        if ($request->occupants->count() !== $request->total_members) {
            throw new InvalidTransitionException(sprintf(
                'The request declares %d member(s) but %d occupant record(s) were provided.',
                $request->total_members,
                $request->occupants->count(),
            ));
        }

        if ($request->occupants->where('is_primary', true)->count() !== 1) {
            throw new InvalidTransitionException('Exactly one occupant must be marked as the primary guest.');
        }

        if ($requireIdentityProof && $request->documents->isEmpty()) {
            throw new InvalidTransitionException('An identity proof must be attached before submitting.');
        }

        if ($request->purpose->needsTrainingProgramme() && blank($request->training_programme)) {
            throw new InvalidTransitionException('The training programme name is required for a training visit.');
        }

        if ($request->purpose->needsHost()
            && $request->host_employee_id === null
            && blank($request->guest_of_name)) {
            throw new InvalidTransitionException('A guest visit must name the host employee.');
        }

        // A request must be able to reach a Manager's review queue. For a
        // regular user that is their reporting manager; for a Manager or the ADG
        // raising their own booking it is any active Manager. If neither can be
        // resolved the request has nowhere to go, so we refuse clearly rather
        // than orphan it in a queue no Manager can open.
        if ($this->resolveReviewingManagerId($request->requester) === null) {
            $isPrivileged = in_array(
                $request->requester->roleSlug(),
                [RoleSlug::MANAGER, RoleSlug::ADG, RoleSlug::ADMIN],
                true,
            );

            throw new InvalidTransitionException(
                $isPrivileged
                    ? 'No Manager is available to review requests. Please contact the administrator.'
                    : 'Your account has no reporting manager assigned. Please contact the administrator.'
            );
        }
    }

    /**
     * Update a request that is still editable, replacing its occupant rows.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $occupants
     * @param  array<int, UploadedFile>  $files
     */
    public function update(
        BookingRequest $request,
        User $actor,
        array $data,
        array $occupants,
        array $files = [],
    ): BookingRequest {
        if (! $request->isEditable()) {
            throw new InvalidTransitionException(
                'This request is under review and can no longer be edited.'
            );
        }

        return DB::transaction(function () use ($request, $actor, $data, $occupants, $files) {
            $request->fill($this->normalise($data));
            $request->nights = $this->nights($request->check_in_date, $request->check_out_date);
            $request->rooms_needed = $this->roomsNeeded((int) $request->total_members, $request->preferred_room_type_id);
            $request->save();

            // Occupants are replaced wholesale rather than diffed. The form posts
            // a complete list, and reconciling partial edits would add complexity
            // for no user-visible benefit.
            $request->occupants()->delete();
            $this->attachOccupants($request, $occupants);

            foreach ($files as $file) {
                $this->documents->store($request, $file, $actor);
            }

            return $request->refresh();
        });
    }

    /**
     * Cancel a request (T13 / T19).
     */
    public function cancel(BookingRequest $request, User $actor, ?string $reason = null): BookingRequest
    {
        $target = RequestStateMachine::assert(
            from: $request->status,
            action: RequestAction::CANCEL,
            actor: $actor->roleSlug(),
            isOwner: $request->isOwnedBy($actor),
        );

        return DB::transaction(function () use ($request, $target, $reason, $actor) {
            $from = $request->status;

            $request->status = $target;
            $request->cancelled_at = now();
            $request->cancel_reason = $reason;
            $request->save();

            // Rooms held by the Manager (PENDING_ADG) or allotted by the
            // Administration go back to the pool; otherwise a cancelled booking
            // would keep blocking them for its whole date range.
            $this->allotments->releaseAllHeld($request, $actor, $reason ?: 'Request cancelled');

            $this->audit->record(
                subject: $request,
                action: RequestAction::CANCEL->auditAction(),
                actor: $actor,
                from: $from,
                to: $target,
                remarks: $reason,
            );

            // The requester is among the recipients deliberately: an administrator
            // may cancel on their behalf, and the guest must not be left unaware.
            DB::afterCommit(fn () => $this->notifications->dispatch(
                NotificationEvent::REQUEST_CANCELLED,
                $request->fresh(),
            ));

            return $request->refresh();
        });
    }

    // ------------------------------------------------------------------ internals

    /**
     * Choose the Manager who will review this request.
     *
     * For a regular user the reviewing manager is their reporting manager, who
     * is expected to hold the Manager role (the Admin provisions the reporting
     * line, and a user with no reporting manager is refused by
     * assertSubmittable()).
     *
     * A Manager or the ADG may also raise a booking of their own. Their
     * reporting line points upward — a Manager reports to the ADG, the ADG to
     * nobody — so it does not name a reviewing Manager. Before this method
     * existed, manager_id was set from that upward reporting line and the
     * request landed in a queue no Manager could open, so it never appeared in
     * the review list. For those roles we route to any active Manager instead,
     * keeping the request visible and actionable.
     *
     * Returns null only when the requester is a user without a valid Manager in
     * their reporting line, or when the institute has no active Manager at all.
     * Either way assertSubmittable() turns that into a clear refusal rather than
     * an orphaned request.
     */
    private function resolveReviewingManagerId(User $requester): ?int
    {
        $reportingManager = $requester->reportingManager;

        $reportsToAManager = $reportingManager !== null
            && $reportingManager->is_active
            && $reportingManager->roleSlug() === RoleSlug::MANAGER;

        if ($reportsToAManager) {
            return $reportingManager->id;
        }

        // Only a Manager or the ADG raising their own request may fall back to
        // another Manager; a regular user must have a proper reporting manager.
        if (! in_array($requester->roleSlug(), [RoleSlug::MANAGER, RoleSlug::ADG, RoleSlug::ADMIN], true)) {
            return null;
        }

        return $this->anyActiveManagerId();
    }

    /** The earliest-created active Manager, or null if none exists. */
    private function anyActiveManagerId(): ?int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('slug', RoleSlug::MANAGER->value))
            ->orderBy('id')
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $occupants
     * @param  array<int, UploadedFile>  $files
     */
    private function buildDraft(
        User $requester,
        array $data,
        array $occupants,
        array $files,
        ?int $roomsRequested = null,
        string $docType = 'AADHAAR',
    ): BookingRequest {
        $request = new BookingRequest($this->normalise($data));

        $request->user_id = $requester->id;
        $request->request_no = BookingRequest::nextRequestNo();
        $request->status = RequestStatus::DRAFT;
        $request->nights = $this->nights($request->check_in_date, $request->check_out_date);
        $request->total_members = (int) $data['total_members'];
        $request->rooms_needed = $roomsRequested
            ?? $this->roomsNeeded($request->total_members, $request->preferred_room_type_id);

        $request->save();

        $this->attachOccupants($request, $occupants);

        foreach ($files as $file) {
            $this->documents->store($request, $file, $requester, $docType);
        }

        return $request->refresh();
    }

    /**
     * Strip fields that do not belong to the chosen purpose.
     *
     * If an applicant fills in a training programme, then switches to "Self
     * Visit", the stale value must not survive into the record — a reviewer
     * would otherwise see contradictory information.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        $purpose = $data['purpose'] instanceof VisitPurpose
            ? $data['purpose']
            : VisitPurpose::from((string) $data['purpose']);

        if (! $purpose->needsTrainingProgramme()) {
            $data['training_programme'] = null;
        }

        if (! $purpose->needsHost()) {
            $data['host_employee_id'] = null;
            $data['guest_of_name'] = null;
        }

        return $data;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function attachOccupants(BookingRequest $request, array $rows): void
    {
        foreach (array_values($rows) as $index => $row) {
            $occupant = $request->occupants()->make([
                'name' => $row['name'],
                'age' => $row['age'] ?? null,
                'gender' => $row['gender'] ?? null,
                'relation' => $row['relation'] ?? null,
                'id_proof_type' => $row['id_proof_type'] ?? null,
                // The first row is always the primary guest.
                'is_primary' => $index === 0,
            ]);

            $occupant->setIdProofNumber($row['id_proof_number'] ?? null);
            $occupant->save();
        }
    }

    /**
     * Whole nights between two dates. Half-open interval: a stay from the 20th
     * to the 22nd is two nights, and the 22nd is free for the next guest
     * (PLAN.md decision 3).
     */
    private function nights(mixed $from, mixed $to): int
    {
        return (int) Carbon::parse($from)->startOfDay()
            ->diffInDays(Carbon::parse($to)->startOfDay());
    }

    /**
     * Suggested room count. SCHEMA.md section 14 uses the chosen room type's
     * capacity; with no type chosen we assume 2 per room, the commonest
     * configuration in this guest house.
     *
     * This is only ever a suggestion — the administrator may allot more or
     * fewer, because allotment is at the discretion of the authority.
     */
    private function roomsNeeded(int $members, mixed $preferredRoomTypeId = null): int
    {
        $type = $preferredRoomTypeId ? RoomType::find((int) $preferredRoomTypeId) : null;

        return RoomsNeeded::for($members, RoomsNeeded::capacityOf($type));
    }
}
