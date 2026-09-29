<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `number_sequences` — one counter row per series and year (REQ/2026, ALT/2026).
 *
 * WHY. Request and allotment numbers were derived as "last number + 1" read with
 * SELECT ... FOR UPDATE. When the range is empty or the read lands past the last
 * row, InnoDB takes a GAP lock. Gap locks do not conflict with each other, so two
 * transactions both acquire one and then each block the other's INSERT: a
 * deadlock. DoubleBookingRaceTest caught it — two administrators allotting two
 * DIFFERENT rooms at the same moment, one of them receiving a raw SQL error.
 *
 * A single counter row incremented with LAST_INSERT_ID(value + 1) serialises on
 * one ordinary row lock, held only until commit, and cannot deadlock with itself.
 * Counters are seeded from the numbers already issued, so existing data continues
 * its sequence without a collision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->string('name', 30)->primary();   // e.g. "REQ/2026"
            $table->unsignedInteger('value');
            $table->timestamp('updated_at')->nullable();
        });

        foreach ([['booking_requests', 'request_no', 'REQ'], ['allotments', 'allotment_no', 'ALT']] as [$table, $col, $prefix]) {
            $rows = DB::table($table)
                ->selectRaw("SUBSTRING_INDEX({$col}, '/', 2) AS series, MAX(CAST(SUBSTRING_INDEX({$col}, '/', -1) AS UNSIGNED)) AS last")
                ->where($col, 'like', $prefix.'/%')
                ->groupBy('series')
                ->get();

            foreach ($rows as $r) {
                DB::table('number_sequences')->insert(['name' => $r->series, 'value' => (int) $r->last, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
