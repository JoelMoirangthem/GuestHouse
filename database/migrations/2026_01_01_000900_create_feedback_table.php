<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA.md section 13 — `feedback`.
 *
 * One row per request (unique booking_request_id): the Feedback Report's
 * response rate is "feedback rows / completed stays", which is only meaningful
 * if a guest cannot answer twice. The unique key enforces that even if the
 * service check is bypassed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_request_id')->unique()->constrained('booking_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');

            $table->unsignedTinyInteger('rating_cleanliness');
            $table->unsignedTinyInteger('rating_staff');
            $table->unsignedTinyInteger('rating_facilities');
            $table->unsignedTinyInteger('rating_overall');

            $table->text('comments')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index('submitted_at');
        });

        DB::statement('ALTER TABLE feedback ADD CONSTRAINT chk_feedback_ratings CHECK (
            rating_cleanliness BETWEEN 1 AND 5 AND rating_staff BETWEEN 1 AND 5
            AND rating_facilities BETWEEN 1 AND 5 AND rating_overall BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
