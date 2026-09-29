<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestOccupant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'age',
        'gender',
        'relation',
        'is_primary',
        'id_proof_type',
    ];

    /**
     * `id_proof_number` is absent from $fillable on purpose. It is written only
     * through setIdProofNumber(), which also maintains the last-4 digits used
     * for masked display. Allowing mass assignment would make it trivially easy
     * to store a raw Aadhaar number by accident.
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'age' => 'integer',
            // Laravel encrypts and decrypts transparently (AES-256-GCM, APP_KEY).
            'id_proof_number' => 'encrypted',
        ];
    }

    protected $hidden = [
        'id_proof_number',
    ];

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RequestDocument::class);
    }

    /**
     * Store an identity number, keeping the display suffix in step.
     */
    public function setIdProofNumber(?string $number): void
    {
        $clean = $number === null ? null : preg_replace('/\s+/', '', $number);

        if ($clean === null || $clean === '') {
            $this->id_proof_number = null;
            $this->id_proof_last4 = null;

            return;
        }

        $this->id_proof_number = $clean;
        $this->id_proof_last4 = substr($clean, -4);
    }

    /**
     * Masked identity number for display — SECURITY.md section 2.
     *
     * This is what every screen, PDF and export shows. The full value is never
     * rendered; revealing it is a separate, audited administrator action.
     */
    public function maskedIdProof(): ?string
    {
        if ($this->id_proof_last4 === null) {
            return null;
        }

        return match ($this->id_proof_type) {
            'AADHAAR' => 'XXXX XXXX '.$this->id_proof_last4,
            default => str_repeat('X', 6).$this->id_proof_last4,
        };
    }
}
