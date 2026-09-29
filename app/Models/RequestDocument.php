<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'doc_type',
        'original_filename',
        'stored_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'request_occupant_id',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * stored_path is hidden from serialisation. It is an internal filesystem
     * location; leaking it into a JSON response or a debug page would hand an
     * attacker the exact path of an Aadhaar scan.
     */
    protected $hidden = [
        'stored_path',
    ];

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }

    public function occupant(): BelongsTo
    {
        return $this->belongsTo(RequestOccupant::class, 'request_occupant_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function humanSize(): string
    {
        // Show bytes below 1 KB rather than rounding to "0 KB", which reads as a
        // broken or empty upload.
        if ($this->size_bytes < 1024) {
            return $this->size_bytes.' B';
        }

        $kb = $this->size_bytes / 1024;

        return $kb < 1024
            ? number_format($kb, 0).' KB'
            : number_format($kb / 1024, 1).' MB';
    }

    public function docTypeLabel(): string
    {
        return match ($this->doc_type) {
            'AADHAAR' => 'Aadhaar Card',
            'PAN' => 'PAN Card',
            'PASSPORT' => 'Passport',
            'OFFICE_ID' => 'Office ID Card',
            'VOTER_ID' => 'Voter ID Card',
            default => 'Other Document',
        };
    }
}
