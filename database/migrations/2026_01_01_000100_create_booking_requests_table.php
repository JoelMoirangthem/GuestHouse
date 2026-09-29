<?php

use App\Domain\Enums\RequestStatus;
use App\Domain\Enums\VisitPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA.md section 5 — booking_requests, the central table.
 *
 * Note what is absent: there is NO room_id column. Decision 1 makes a request
 * one-to-many with allotments, so rooms live in the `allotments` table. Putting
 * a room on the request was the single easiest schema mistake available here,
 * and it would have quietly capped every booking at one room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 30)->unique();          // REQ/2026/00123

            $table->foreignId('user_id')->constrained('users');  // the requester

            $table->enum('purpose', VisitPurpose::values());
            $table->string('training_programme', 150)->nullable();   // iff TRAINING
            $table->foreignId('host_employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_of_name', 120)->nullable();        // free-text fallback

            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedSmallInteger('nights');
            $table->unsignedSmallInteger('total_members');

            // A preference only. The infographic is explicit that allotment is at
            // the discretion of the authority, so this must never be treated as a
            // reservation of that room type.
            $table->foreignId('preferred_room_type_id')->nullable();
            $table->unsignedSmallInteger('rooms_needed')->default(1);

            $table->string('contact_mobile', 15);
            $table->string('contact_email', 150);
            $table->text('remarks')->nullable();

            $table->string('status', 30)->default(RequestStatus::DRAFT->value);

            // --- approval chain trail
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manager_acted_at')->nullable();
            $table->text('manager_remarks')->nullable();

            $table->text('more_info_note')->nullable();
            $table->timestamp('more_info_at')->nullable();
            $table->timestamp('resubmitted_at')->nullable();

            $table->foreignId('adg_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('adg_acted_at')->nullable();
            $table->text('adg_remarks')->nullable();

            // Audit proof that Core Rule 1 was honoured: availability was looked
            // at only after approval, and only by an administrator.
            $table->timestamp('availability_checked_at')->nullable();
            $table->foreignId('availability_checked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('user_id');
            $table->index(['check_in_date', 'check_out_date']);
            $table->index('purpose');
            $table->index('manager_id');
            $table->index('adg_id');
        });

        // Database-level date sanity. Application validation is the friendly
        // first line; this is the backstop that survives a bad import or a
        // future code path that forgets to validate.
        DB::statement('ALTER TABLE booking_requests
            ADD CONSTRAINT chk_dates CHECK (check_out_date > check_in_date)');

        DB::statement('ALTER TABLE booking_requests
            ADD CONSTRAINT chk_members CHECK (total_members >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_requests');
    }
};
