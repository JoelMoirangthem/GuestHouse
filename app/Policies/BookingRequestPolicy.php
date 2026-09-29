<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Enums\RequestStatus;
use App\Models\BookingRequest;
use App\Models\User;

/**
 * Layer 3 of the three authorization layers in SECURITY.md section 4.
 *
 * Middleware answers "may this role enter this area". This policy answers the
 * per-record questions middleware cannot: is this the applicant's own request,
 * is it one of my reportees', and is the request in a state where this action
 * still makes sense.
 */
class BookingRequestPolicy
{
    /**
     * Who may read a request.
     *
     * Scope widens with responsibility: an employee sees only their own, a
     * manager sees their reportees', and the ADG and administrator see all —
     * because both act on every request in the institute.
     */
    public function view(User $user, BookingRequest $request): bool
    {
        if ($request->isOwnedBy($user)) {
            return true;
        }

        if ($user->isAdmin() || $user->isAdg()) {
            return true;
        }

        if ($user->isManager()) {
            // Either a reportee's request, or one already routed to this manager.
            return $request->requester->reporting_manager_id === $user->id
                || $request->manager_id === $user->id;
        }

        // A guest visit is visible to the host employee, who is accountable for it.
        return $request->host_employee_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('request.create');
    }

    /**
     * Editing is limited to the owner, and only while the request is a draft or
     * has been returned for more information. Once an approver holds it, the
     * facts under review must not shift beneath them.
     */
    public function update(User $user, BookingRequest $request): bool
    {
        return $request->isOwnedBy($user) && $request->isEditable();
    }

    public function submit(User $user, BookingRequest $request): bool
    {
        return $request->isOwnedBy($user)
            && in_array($request->status, [RequestStatus::DRAFT, RequestStatus::MORE_INFO_MANAGER], true);
    }

    /**
     * The owner may withdraw; an administrator may cancel on their behalf.
     * Neither may do so once the stay has begun — that is a checkout, not a
     * cancellation.
     */
    public function cancel(User $user, BookingRequest $request): bool
    {
        if ($request->status->isTerminal() || $request->status === RequestStatus::CHECKED_IN) {
            return false;
        }

        return $request->isOwnedBy($user) || $user->isAdmin();
    }

    /**
     * Viewing an attached identity document.
     *
     * Reuses view() so document visibility can never drift wider than request
     * visibility — a subtle bug that would otherwise be easy to introduce.
     */
    public function viewDocuments(User $user, BookingRequest $request): bool
    {
        return $this->view($user, $request);
    }

    /**
     * Rating a completed stay. Owner only — WORKFLOW.md section 3 grants
     * "Submit feedback" to the applicant and nobody else, so an administrator
     * cannot fill it in on a guest's behalf and inflate the Feedback Report.
     */
    public function submitFeedback(User $user, BookingRequest $request): bool
    {
        return $request->isOwnedBy($user)
            && $user->hasPermission('feedback.submit')
            && $request->stayCompleted();
    }

    /**
     * Revealing a full Aadhaar or other identity number.
     *
     * Administrator only, and audited at the point of use. Everyone else — the
     * ADG and managers included — sees the masked form, because approving a
     * booking never requires the full number.
     */
    public function revealIdProof(User $user, BookingRequest $request): bool
    {
        return $user->isAdmin();
    }
}
