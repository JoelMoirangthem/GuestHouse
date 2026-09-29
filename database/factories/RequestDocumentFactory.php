<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BookingRequest;
use App\Models\RequestDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RequestDocument>
 */
class RequestDocumentFactory extends Factory
{
    protected $model = RequestDocument::class;

    public function definition(): array
    {
        $uuid = Str::uuid()->toString();

        return [
            'booking_request_id' => BookingRequest::factory(),
            'doc_type' => 'AADHAAR',
            'original_filename' => 'Aadhaar Card.pdf',
            'stored_path' => 'id-proofs/'.now()->format('Y').'/REQ-TEST/'.$uuid.'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 204800,
            'sha256' => hash('sha256', $uuid),
            'uploaded_by' => User::factory(),
        ];
    }
}
