<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Enums\RequestStatus;
use App\Models\BookingRequest;
use App\Models\RequestOccupant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ID-proof retention — SECURITY.md section 2: "ID proofs purged 12 months after
 * CHECKED_OUT via a scheduled command. Retention window lives in settings."
 *
 * What is purged, once a request is past the window:
 *   - every uploaded document file, and its row
 *   - the encrypted full identity number on each occupant
 * What is kept: the last four digits (so history screens still show the masked
 * form), the request itself, and its audit trail.
 *
 * Which requests. The specification names completed stays. Rejected and
 * cancelled requests are included too, anchored on when they were closed:
 * otherwise an applicant who was turned away would have their Aadhaar scan kept
 * forever, longer than a guest who actually stayed.
 */
class IdProofRetentionService
{
    public function __construct(
        private readonly DocumentStorageService $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function cutoff(): Carbon
    {
        return now()->subMonths((int) config('gh.id_proof_retention_months', 12))->startOfDay();
    }

    /** @return Collection<int, BookingRequest> requests past the window still holding ID data */
    public function due(): Collection
    {
        $cutoff = $this->cutoff();

        $holdsData = fn ($q) => $q->where(fn ($w) => $w
            ->whereHas('documents')
            ->orWhereHas('occupants', fn ($o) => $o->whereNotNull('id_proof_number')));

        $completed = BookingRequest::query()
            ->whereIn('status', [RequestStatus::CHECKED_OUT->value, RequestStatus::EARLY_CHECKOUT->value])
            // Anchored on the LAST room to leave, so a multi-room stay is judged by its end.
            ->whereHas('allotments', fn ($a) => $a->whereNotNull('actual_check_out_at'))
            ->whereDoesntHave('allotments', fn ($a) => $a->where(fn ($w) => $w
                ->whereNull('actual_check_out_at')->whereIn('status', ['ALLOTTED', 'CHECKED_IN'])
                ->orWhere('actual_check_out_at', '>=', $cutoff)))
            ->where($holdsData)
            ->get();

        $closed = BookingRequest::query()
            ->whereIn('status', [RequestStatus::REJECTED_MANAGER->value, RequestStatus::REJECTED_ADG->value, RequestStatus::CANCELLED->value])
            ->where('updated_at', '<', $cutoff)
            ->where($holdsData)
            ->get();

        return $completed->merge($closed)->unique('id')->values();
    }

    /**
     * @return array{requests: int, files: int, numbers: int}
     */
    public function purge(bool $dryRun = false): array
    {
        $totals = ['requests' => 0, 'files' => 0, 'numbers' => 0];

        foreach ($this->due() as $request) {
            $files = $request->documents()->count();
            $numbers = $request->occupants()->whereNotNull('id_proof_number')->count();

            $totals['requests']++;
            $totals['files'] += $files;
            $totals['numbers'] += $numbers;

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($request, $files, $numbers) {
                foreach ($request->documents()->get() as $doc) {
                    $this->storage->delete($doc);
                }

                // Direct update: the encrypted column is not mass-assignable, and
                // the last four digits must survive for the masked display.
                RequestOccupant::where('booking_request_id', $request->id)
                    ->whereNotNull('id_proof_number')
                    ->update(['id_proof_number' => null]);

                $this->audit->record($request, 'ID_PROOFS_PURGED', null, metadata: [
                    'files' => $files,
                    'numbers' => $numbers,
                    'retention_months' => (int) config('gh.id_proof_retention_months', 12),
                ]);
            });
        }

        return $totals;
    }
}
