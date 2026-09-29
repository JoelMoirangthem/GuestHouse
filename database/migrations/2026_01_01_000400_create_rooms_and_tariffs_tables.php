<?php

use App\Domain\Enums\RoomStatus;
use App\Domain\Enums\VisitPurpose;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA.md sections 8, 9 and 10 — room_types, rooms, tariffs.
 *
 * Room types and tariffs are TABLES, not enums (PLAN.md decision 4). An
 * administrator must be able to add a room type or revise a rate without a code
 * deploy, and tariffs additionally need history so that a rate change cannot
 * retroactively rewrite last quarter's revenue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();          // DELUXE_AC, SUITE_AC, ...
            $table->string('name', 80);                     // "Deluxe AC"
            $table->enum('category', ['VIP', 'NORMAL']);
            $table->unsignedTinyInteger('default_capacity')->default(2);
            $table->boolean('has_ac')->default(true);
            $table->string('description', 255)->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category');
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('room_number', 20)->unique();
            $table->foreignId('room_type_id')->constrained('room_types');

            $table->tinyInteger('floor')->nullable();
            $table->string('block', 30)->nullable();

            // NULL means "use the room type's default_capacity". An explicit value
            // lets one oversized room differ without inventing a new type.
            $table->unsignedTinyInteger('capacity')->nullable();

            $table->enum('status', RoomStatus::values())->default(RoomStatus::ACTIVE->value);
            $table->string('block_reason', 255)->nullable();

            // A date-ranged block, independent of `status`. blocked_to may be NULL
            // meaning open-ended — the availability query must use COALESCE, not a
            // bare comparison, or NOT(NULL) leaves the room looking available.
            $table->date('blocked_from')->nullable();
            $table->date('blocked_to')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('room_type_id');
            $table->index('status');
        });

        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();

            // NULL purpose = applies to every purpose. A purpose-specific row wins.
            $table->enum('purpose', VisitPurpose::values())->nullable();

            $table->decimal('amount_per_night', 10, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();       // NULL = open-ended
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['room_type_id', 'effective_from']);
        });

        DB::statement('ALTER TABLE tariffs
            ADD CONSTRAINT chk_tariff_amount CHECK (amount_per_night >= 0)');

        DB::statement('ALTER TABLE rooms
            ADD CONSTRAINT chk_block_range CHECK (blocked_to IS NULL OR blocked_from IS NULL OR blocked_to >= blocked_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tariffs');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_types');
    }
};
