<?php

use App\Domain\Enums\AllotmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA.md section 11 — allotments. One row per room per request.
 *
 * This table carries the fourth and last layer of the double-booking defence.
 * The other three are in AllotmentService: a transaction, a SELECT ... FOR UPDATE
 * row lock, and a re-check of the overlap predicate after the lock is held.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allotments', function (Blueprint $table) {
            $table->id();
            $table->string('allotment_no', 30)->unique();        // ALT/2026/00456

            $table->foreignId('booking_request_id')->constrained('booking_requests')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms');

            // Copied from the request at allotment time. They may later diverge:
            // an approved extension moves check_out_date on this row only.
            $table->date('check_in_date');
            $table->date('check_out_date');

            $table->unsignedTinyInteger('occupants_count')->default(1);

            $table->enum('status', AllotmentStatus::values())
                ->default(AllotmentStatus::ALLOTTED->value);

            // SNAPSHOT, not a live lookup. A tariff revision must never rewrite
            // the revenue of a stay that already happened.
            $table->decimal('rate_per_night', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);

            $table->foreignId('allotted_by')->constrained('users');
            $table->timestamp('allotted_at')->nullable();

            $table->dateTime('actual_check_in_at')->nullable();
            $table->dateTime('actual_check_out_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();

            $table->char('qr_token', 40)->nullable()->unique();
            $table->string('cancel_reason', 255)->nullable();

            $table->timestamps();

            // Serves the overlap query: room first, then status, then the dates.
            $table->index(['room_id', 'status', 'check_in_date', 'check_out_date'], 'idx_overlap');
            $table->index('booking_request_id');
        });

        /*
         * The concurrency backstop.
         *
         * `occupies` holds check_in_date only while the allotment actually holds
         * the room, and NULL otherwise. MySQL permits unlimited NULLs in a unique
         * index, so cancelled and vacated rows are exempt while two live
         * allotments cannot share a start date on the same room.
         *
         * HONEST LIMITATION: this catches identical start dates only, not partial
         * overlaps. It is a safety net for the case where application logic is
         * bypassed — the transaction plus row lock in AllotmentService remains the
         * primary defence. Verified against MySQL 8.4.9: a duplicate active row is
         * rejected with error 1062.
         */
        DB::statement("
            ALTER TABLE allotments
            ADD COLUMN occupies DATE
            GENERATED ALWAYS AS (
                CASE WHEN status IN ('ALLOTTED','CHECKED_IN') THEN check_in_date ELSE NULL END
            ) STORED
        ");

        DB::statement('ALTER TABLE allotments ADD UNIQUE KEY uq_room_occupies (room_id, occupies)');

        DB::statement('ALTER TABLE allotments
            ADD CONSTRAINT chk_allotment_dates CHECK (check_out_date > check_in_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('allotments');
    }
};
