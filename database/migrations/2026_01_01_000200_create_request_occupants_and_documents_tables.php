<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA.md sections 6 and 7 — occupants and their identity documents.
 *
 * These two tables are why the "Guest Visit" purpose is implementable at all:
 * the person staying is frequently not the person requesting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_occupants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_request_id')->constrained('booking_requests')->cascadeOnDelete();

            $table->string('name', 120);
            $table->unsignedTinyInteger('age')->nullable();
            $table->enum('gender', ['M', 'F', 'O'])->nullable();
            $table->string('relation', 60)->nullable();
            $table->boolean('is_primary')->default(false);

            $table->enum('id_proof_type', ['AADHAAR', 'PAN', 'PASSPORT', 'OFFICE_ID', 'OTHER'])->nullable();

            // Encrypted at rest via Laravel's Crypt (AES-256-GCM). VARBINARY
            // because ciphertext is binary, not text. SECURITY.md section 2.
            $table->binary('id_proof_number')->nullable();

            // Kept in clear solely so the UI can render XXXX XXXX 1234 without
            // decrypting — decryption is an audited action.
            $table->char('id_proof_last4', 4)->nullable();

            $table->timestamps();

            $table->index('booking_request_id');
        });

        Schema::create('request_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_request_id')->constrained('booking_requests')->cascadeOnDelete();
            $table->foreignId('request_occupant_id')->nullable()->constrained('request_occupants')->nullOnDelete();

            $table->enum('doc_type', ['AADHAAR', 'PAN', 'PASSPORT', 'OFFICE_ID', 'OTHER']);

            $table->string('original_filename', 255);

            // Path under storage/app/private/. NEVER rendered as a URL: files are
            // streamed by an authorising controller that writes an audit row.
            $table->string('stored_path', 255);

            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);

            $table->foreignId('uploaded_by')->constrained('users');

            $table->timestamps();

            $table->index('booking_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_documents');
        Schema::dropIfExists('request_occupants');
    }
};
