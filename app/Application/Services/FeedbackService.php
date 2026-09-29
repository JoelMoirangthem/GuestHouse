<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\BookingRequest;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Feedback capture — workflow step 7, PLAN.md Phase 9.
 *
 * Feedback does not change the request's status: CHECKED_OUT and
 * EARLY_CHECKOUT are terminal (WORKFLOW.md section 1), and rating a stay is not
 * a transition. It is still audited, because it feeds a report.
 */
class FeedbackService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{rating_cleanliness:int, rating_staff:int, rating_facilities:int, rating_overall:int, comments?:?string}  $answers
     */
    public function submit(BookingRequest $request, User $user, array $answers): Feedback
    {
        if (! $request->isOwnedBy($user)) {
            throw new RuntimeException('Only the applicant may give feedback on this stay.');
        }

        if (! $user->hasPermission('feedback.submit')) {
            throw new RuntimeException('Your account is not permitted to submit feedback.');
        }

        try {
            return DB::transaction(function () use ($request, $user, $answers) {
                // Lock the request so a double-click cannot race past the check.
                $request = BookingRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

                if (! $request->stayCompleted()) {
                    throw new RuntimeException('Feedback can be given once your stay is complete.');
                }

                if (Feedback::where('booking_request_id', $request->id)->exists()) {
                    throw new RuntimeException('Feedback for this stay has already been recorded. Thank you.');
                }

                $feedback = new Feedback;
                $feedback->fill([
                    'rating_cleanliness' => (int) $answers['rating_cleanliness'],
                    'rating_staff' => (int) $answers['rating_staff'],
                    'rating_facilities' => (int) $answers['rating_facilities'],
                    'rating_overall' => (int) $answers['rating_overall'],
                    'comments' => filled($answers['comments'] ?? null) ? trim((string) $answers['comments']) : null,
                ]);
                $feedback->booking_request_id = $request->id;
                $feedback->user_id = $user->id;
                $feedback->submitted_at = now();
                $feedback->save();

                // Ratings only in the audit row. Comments are free text the guest
                // wrote for the Administration, not for the audit trail.
                $this->audit->record($request, 'FEEDBACK_SUBMITTED', $user, metadata: [
                    'overall' => $feedback->rating_overall,
                ]);

                return $feedback;
            });
        } catch (QueryException $e) {
            // Layer 2: the unique key on booking_request_id.
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                throw new RuntimeException('Feedback for this stay has already been recorded. Thank you.');
            }

            throw $e;
        }
    }
}
