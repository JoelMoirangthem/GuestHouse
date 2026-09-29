<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\RequestOccupant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The one place a full identity number is shown — SECURITY.md section 2:
 * "Full value is never rendered — not even for Admin, unless an explicit
 * 'reveal' action is taken, which is itself audited."
 *
 * Deliberately a POST that returns the page directly rather than a redirect:
 * a redirect would have to carry the number through the session, which is
 * stored in the database. The number exists only in this one response, which
 * no browser or proxy may cache.
 */
class IdProofRevealController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(Request $request, RequestOccupant $occupant): Response
    {
        $occupant->loadMissing('bookingRequest');
        $this->authorize('revealIdProof', $occupant->bookingRequest);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => 'Record why the full number is needed. The reveal is audited.',
        ]);

        $number = $occupant->id_proof_number;
        abort_if(blank($number), 404, 'No identity number is held for this occupant.');

        // The reason is recorded; the number itself never is.
        $this->audit->record(
            subject: $occupant->bookingRequest,
            action: 'ID_PROOF_REVEALED',
            actor: $request->user(),
            remarks: $data['reason'],
            metadata: ['occupant_id' => $occupant->id, 'id_proof_type' => $occupant->id_proof_type],
        );

        return response()
            ->view('admin.occupants.revealed', [
                'occupant' => $occupant,
                'request' => $occupant->bookingRequest,
                'number' => $number,
            ])
            ->header('Cache-Control', 'no-store, max-age=0, must-revalidate')
            ->header('Pragma', 'no-cache');
    }
}
