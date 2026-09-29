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
         * A live allotment (status ALLOTTED or CHECKED_IN) may not share a start
         * date with another live allotment for the same room. Cancelled and
         * vacated rows are exempt. This catches identical start dates only, not
         * partial overlaps; the transaction + row lock in AllotmentService is the
         * primary defence.
         *
         * MySQL and Postgres express this differently, so the statement is chosen
         * per driver:
         *   - MySQL: a STORED generated column `occupies` (= check_in_date while
         *     live, NULL otherwise) + a unique key. MySQL allows unlimited NULLs
         *     in a unique index, so exempt rows do not collide.
         *   - Postgres: a partial unique index directly on (room_id, check_in_date)
         *     WHERE status IN ('ALLOTTED','CHECKED_IN') — no extra column needed.
         */
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX uq_room_occupies ON allotments (room_id, check_in_date)
                WHERE status IN ('ALLOTTED','CHECKED_IN')
            ");

            DB::statement('ALTER TABLE allotments
                ADD CONSTRAINT chk_allotment_dates CHECK (check_out_date > check_in_date)');
        } else {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('allotments');
    }
};
