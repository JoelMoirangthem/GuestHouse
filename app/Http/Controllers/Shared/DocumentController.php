<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shared;

use App\Application\Services\AuditLogger;
use App\Application\Services\DocumentStorageService;
use App\Http\Controllers\Controller;
use App\Models\RequestDocument;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ONLY route by which an identity document can be read.
 *
 * Files sit on the `private` disk with serving disabled, so there is no URL that
 * maps to them directly. Every read passes through here: authenticate, authorise
 * against the parent request's policy, record the access, then stream.
 *
 * SECURITY.md section 2.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentStorageService $storage,
    ) {}

    public function show(Request $request, RequestDocument $document): StreamedResponse
    {
        $document->loadMissing('bookingRequest.requester');

        // Throws 403 unless the viewer may see the parent request.
        $this->authorize('viewDocuments', $document->bookingRequest);

        abort_unless($this->storage->exists($document), 404, 'The document is no longer available.');

        $this->recordAccess($request, $document);

        // Inline so a reviewer can read an Aadhaar scan without downloading a
        // copy to their machine; the filename is the applicant's original one.
        return response()->streamDownload(
            function () use ($document) {
                $handle = fopen($this->storage->pathFor($document), 'rb');
                fpassthru($handle);
                fclose($handle);
            },
            $document->original_filename,
            [
                'Content-Type' => $document->mime_type,
                'Content-Length' => (string) $document->size_bytes,
                'Content-Disposition' => 'inline; filename="'.addslashes($document->original_filename).'"',
                // Never let a proxy or the browser retain a copy of an ID scan.
                'Cache-Control' => 'no-store, max-age=0, must-revalidate',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Records who looked at which identity document — SECURITY.md sections 2
     * and 5: "every document view written to audit_logs".
     *
     * The row is attached to the parent REQUEST, so the access shows up in that
     * request's history panel beside the approvals it informed.
     */
    private function recordAccess(Request $request, RequestDocument $document): void
    {
        app(AuditLogger::class)->record(
            subject: $document->bookingRequest,
            action: 'DOCUMENT_VIEWED',
            actor: $request->user(),
            metadata: ['document_id' => $document->id, 'doc_type' => $document->doc_type],
        );
    }
}
