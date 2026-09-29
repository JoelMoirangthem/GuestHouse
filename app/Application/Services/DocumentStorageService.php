<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\BookingRequest;
use App\Models\RequestDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores and retrieves identity documents — SECURITY.md section 2.
 *
 * Every rule in here exists because a leaked Aadhaar scan is the worst
 * realistic failure of this system:
 *
 *  - files live on the `private` disk, outside the web root, never symlinked
 *  - the stored filename is a random UUID, so a path cannot be guessed from a
 *    person's name or request number
 *  - the MIME type is taken from content sniffing, not the supplied extension
 *  - a SHA-256 is recorded so tampering is detectable
 */
class DocumentStorageService
{
    private const DISK = 'private';

    /**
     * MIME types we are willing to accept, sniffed from the file itself.
     * An attacker can rename shell.php to shell.pdf; they cannot easily make it
     * sniff as application/pdf.
     */
    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function store(
        BookingRequest $request,
        UploadedFile $file,
        User $uploader,
        string $docType = 'AADHAAR',
        ?int $occupantId = null,
    ): RequestDocument {
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new RuntimeException(
                "Unsupported file type '{$mime}'. Upload a PDF, JPG or PNG."
            );
        }

        $maxBytes = config('gh.upload.max_kb') * 1024;

        if ($file->getSize() > $maxBytes) {
            throw new RuntimeException('The file exceeds the maximum permitted size of 5 MB.');
        }

        // Photos from a phone carry EXIF metadata, often including the GPS
        // position where they were taken (SECURITY.md section 2). Re-encoding
        // through GD writes pixels only, so every metadata block is dropped.
        $source = $this->isImage($mime) ? $this->reencode($file->getRealPath(), $mime) : $file->getRealPath();

        $hash = hash_file('sha256', $source);

        // storage/app/private/id-proofs/2026/REQ-2026-00001/<uuid>.pdf
        $directory = sprintf(
            'id-proofs/%s/%s',
            now()->format('Y'),
            str_replace('/', '-', $request->request_no),
        );

        $filename = Str::uuid()->toString().'.'.$this->extensionFor($mime);

        $path = Storage::disk(self::DISK)->putFileAs($directory, new \Illuminate\Http\File($source), $filename);

        if ($source !== $file->getRealPath()) {
            @unlink($source);
        }

        if ($path === false) {
            throw new RuntimeException('The document could not be stored.');
        }

        $document = $request->documents()->make([
            'request_occupant_id' => $occupantId,
            'doc_type' => $docType,
            // Original name is retained for display only; it is never used to
            // build a filesystem path.
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'mime_type' => $mime,
            'size_bytes' => (int) filesize($this->pathForStored($path)),
            'sha256' => $hash,
        ]);

        // Set explicitly rather than added to $fillable: the uploader's identity
        // is established from the session, never from request input.
        $document->uploaded_by = $uploader->id;
        $document->save();

        return $document;
    }

    /**
     * Absolute path for streaming. Callers MUST have passed a policy check first.
     */
    public function pathFor(RequestDocument $document): string
    {
        if (! Storage::disk(self::DISK)->exists($document->stored_path)) {
            throw new RuntimeException('The stored document is missing from disk.');
        }

        return Storage::disk(self::DISK)->path($document->stored_path);
    }

    public function exists(RequestDocument $document): bool
    {
        return Storage::disk(self::DISK)->exists($document->stored_path);
    }

    /**
     * Confirm the file has not been altered since upload.
     */
    public function verifyIntegrity(RequestDocument $document): bool
    {
        if (! $this->exists($document)) {
            return false;
        }

        return hash_file('sha256', $this->pathFor($document)) === $document->sha256;
    }

    public function delete(RequestDocument $document): void
    {
        Storage::disk(self::DISK)->delete($document->stored_path);
        $document->delete();
    }

    private function isImage(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png'], true);
    }

    /**
     * Decode and re-encode an image, returning the path of a clean temp file.
     *
     * Dimensions are checked from the header BEFORE decoding: a 5 MB PNG can
     * declare 30000 × 30000 pixels and exhaust memory when GD expands it.
     */
    private function reencode(string $path, string $mime): string
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new RuntimeException('The image could not be read. Upload a clear JPG, PNG or PDF.');
        }

        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w * $h > 40_000_000) {
            throw new RuntimeException('The image is too large to process. Scan it at a lower resolution, or upload a PDF.');
        }

        $img = $mime === 'image/png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if ($img === false) {
            throw new RuntimeException('The image could not be read. Upload a clear JPG, PNG or PDF.');
        }

        $out = tempnam(sys_get_temp_dir(), 'ghimg');

        try {
            if ($mime === 'image/png') {
                imagesavealpha($img, true);
                $ok = imagepng($img, $out, 6);
            } else {
                $ok = imagejpeg($img, $out, 90);
            }
        } finally {
            imagedestroy($img);
        }

        if (! $ok) {
            @unlink($out);
            throw new RuntimeException('The image could not be processed.');
        }

        return $out;
    }

    private function pathForStored(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };
    }
}
