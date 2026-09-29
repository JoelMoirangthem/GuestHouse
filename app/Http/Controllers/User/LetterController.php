<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Application\Services\AllotmentLetterService;
use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The applicant's own allotment letter (ROUTES.md: "allotment PDF, own only").
 *
 * Deliberately narrower than BookingRequestPolicy::view — a manager may read a
 * reportee's request, but the letter carries the check-in QR code, which is a
 * bearer credential for the desk. Only the applicant takes it home.
 */
class LetterController extends Controller
{
    public function __construct(
        private readonly AllotmentLetterService $letters,
    ) {}

    public function show(Request $request, BookingRequest $bookingRequest): Response
    {
        abort_unless($bookingRequest->isOwnedBy($request->user()), 403);
        abort_unless($this->letters->isIssuable($bookingRequest), 409,
            'The allotment letter is available once all rooms are allotted.');

        $letter = $this->letters->render($bookingRequest);

        return response($letter['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$letter['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
