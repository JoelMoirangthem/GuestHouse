<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Application\Services\StayService;
use App\Domain\StateMachine\InvalidTransitionException;
use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The guest's request to stay longer (T16).
 *
 * Note what this does NOT do: it never tells the applicant whether the extra
 * nights are free. That is availability information, and Core Rule 2 withholds it
 * from users. The request is recorded and the Administration decides.
 */
class ExtensionController extends Controller
{
    public function __construct(
        private readonly StayService $stays,
    ) {}

    public function store(Request $request, BookingRequest $bookingRequest): RedirectResponse
    {
        $this->authorize('view', $bookingRequest);

        abort_unless(
            $bookingRequest->isOwnedBy($request->user()) || $request->user()->isAdmin(),
            403,
            'You may only extend your own stay.'
        );

        $validated = $request->validate([
            'requested_check_out_date' => [
                'required', 'date_format:Y-m-d',
                'after:'.$bookingRequest->check_out_date->format('Y-m-d'),
            ],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'requested_check_out_date.after' => 'The new date must be later than your current check-out date.',
        ]);

        try {
            $this->stays->requestExtension(
                $bookingRequest,
                $request->user(),
                $validated['requested_check_out_date'],
                $validated['reason'] ?? null,
            );
        } catch (InvalidTransitionException|\RuntimeException $e) {
            return back()->withErrors(['extension' => $e->getMessage()]);
        }

        return back()->with('success',
            'Your extension request has been sent to the Administration for a decision.');
    }
}
