<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Contracts\PdfGenerator;
use App\Domain\Contracts\QrGenerator;
use App\Models\Allotment;
use App\Models\BookingRequest;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The allotment letter (SCREENS.md 3.5 "Print / PDF"): every room held by a
 * request, with a QR code the front desk scans to check the party in.
 *
 * One letter per request, not per room. Check-in admits the whole party at
 * once (StayService::checkIn), and any held allotment's token resolves to the
 * same request, so a single code is enough.
 */
class AllotmentLetterService
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly QrGenerator $qr,
    ) {}

    public function isIssuable(BookingRequest $request): bool
    {
        return $request->status->issuesLetter();
    }

    /**
     * @return array{filename: string, bytes: string}
     *
     * @throws RuntimeException when the request is not in an issuable state.
     */
    public function render(BookingRequest $request, ?Allotment $scanTarget = null): array
    {
        if (! $this->isIssuable($request)) {
            throw new RuntimeException('An allotment letter is available only once all rooms are allotted.');
        }

        $request->loadMissing(['requester', 'occupants']);

        $allotments = Allotment::query()
            ->where('booking_request_id', $request->id)
            ->occupying()
            ->with('room.roomType')
            ->orderBy('id')
            ->get();

        $scanTarget ??= $allotments->first();

        if ($scanTarget === null || $scanTarget->qr_token === null) {
            throw new RuntimeException('This request holds no room that can be checked in.');
        }

        $bytes = $this->pdf->fromView('letters.allotment', [
            'request' => $request,
            'allotments' => $allotments,
            'qr' => $this->qr->dataUri($scanTarget->qr_token, 180),
            'token' => $scanTarget->qr_token,
            'total' => $allotments->sum(fn (Allotment $a) => (float) $a->total_amount),
            'issuedAt' => now(),
        ]);

        return [
            'filename' => 'allotment-letter-'.Str::slug($request->request_no).'.pdf',
            'bytes' => $bytes,
        ];
    }
}
