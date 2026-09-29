<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Application\Services\FeedbackService;
use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Models\Feedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Post-stay feedback — ROUTES.md `/my/requests/{request}/feedback`.
 */
class FeedbackController extends Controller
{
    public function __construct(
        private readonly FeedbackService $feedback,
    ) {}

    public function create(Request $request, BookingRequest $bookingRequest): View|RedirectResponse
    {
        $this->authorize('submitFeedback', $bookingRequest);

        if ($bookingRequest->feedback()->exists()) {
            return redirect()->route('my.requests.show', $bookingRequest)
                ->with('success', 'Your feedback for this stay has already been recorded. Thank you.');
        }

        return view('user.requests.feedback', [
            'request' => $bookingRequest,
            'aspects' => Feedback::ASPECTS,
        ]);
    }

    public function store(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $this->authorize('submitFeedback', $bookingRequest);

        $rating = ['required', 'integer', 'between:1,5'];
        $answers = $request->validate([
            'rating_cleanliness' => $rating,
            'rating_staff' => $rating,
            'rating_facilities' => $rating,
            'rating_overall' => $rating,
            'comments' => ['nullable', 'string', 'max:2000'],
        ], [
            'rating_*.required' => 'Please choose a rating from 1 to 5.',
            'rating_*.between' => 'Ratings run from 1 to 5.',
        ], array_map('strtolower', Feedback::ASPECTS));

        try {
            $this->feedback->submit($bookingRequest, $request->user(), $answers);
        } catch (RuntimeException $e) {
            return redirect()->route('my.requests.show', $bookingRequest)->withErrors(['feedback' => $e->getMessage()]);
        }

        return redirect()->route('my.requests.show', $bookingRequest)
            ->with('success', 'Thank you — your feedback has been recorded.');
    }
}
