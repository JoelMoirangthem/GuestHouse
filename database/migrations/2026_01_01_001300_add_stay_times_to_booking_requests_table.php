<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expected arrival and departure times, captured by the public requisition form.
 *
 * Nullable because requests raised through the signed-in form do not ask for
 * them. Times are informational for the front desk only: occupancy and
 * overlap are still computed on whole dates (PLAN.md decision 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_requests', function (Blueprint $table) {
            $table->time('check_in_time')->nullable()->after('check_in_date');
            $table->time('check_out_time')->nullable()->after('check_out_date');
        });
    }

    public function down(): void
    {
        Schema::table('booking_requests', function (Blueprint $table) {
            $table->dropColumn(['check_in_time', 'check_out_time']);
        });
    }
};
