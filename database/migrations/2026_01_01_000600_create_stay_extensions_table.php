<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA.md section 12 — stay_extensions.
 *
 * An extension is not a free action. Granting one re-opens the availability
 * question for the extra nights on the SAME room, because somebody else may
 * already hold it. The decision is therefore recorded as its own row with its
 * own outcome, rather than being a silent edit to the allotment's dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_extensions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_request_id')->constrained('booking_requests')->cascadeOnDelete();
            $table->foreignId('allotment_id')->constrained('allotments')->cascadeOnDelete();

            $table->date('previous_check_out_date');
            $table->date('requested_check_out_date');

            $table->enum('status', ['REQUESTED', 'APPROVED', 'DENIED'])->default('REQUESTED');

            $table->text('reason')->nullable();

            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('denial_reason', 255)->nullable();

            $table->timestamps();

            $table->index('booking_request_id');
            $table->index(['allotment_id', 'status']);
        });

        // An extension must actually extend the stay.
        DB::statement('ALTER TABLE stay_extensions
            ADD CONSTRAINT chk_extension_forward
            CHECK (requested_check_out_date > previous_check_out_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_extensions');
    }
};
