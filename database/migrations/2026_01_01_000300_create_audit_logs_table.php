<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA.md section 13 — audit_logs.
 *
 * Brought forward from Phase 9 because screen 3.4 (Manager Review) requires a
 * History panel, and building that panel against no data source would mean
 * writing it twice.
 *
 * APPEND-ONLY. No application code issues UPDATE or DELETE against this table.
 * An approval trail that can be edited is not an approval trail — in a
 * government workflow it is the only defence against undetectable tampering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Polymorphic: today booking requests and documents, later allotments,
            // rooms and tariffs.
            $table->string('auditable_type', 120);
            $table->unsignedBigInteger('auditable_id');

            $table->string('action', 60);            // SUBMITTED, ADG_APPROVED, ...
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();

            // Nullable so a system or scheduled action can be recorded with no
            // human actor, rather than being left unlogged.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 30)->nullable();

            $table->text('remarks')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();

            // Only created_at. There is no updated_at because a row that could be
            // updated would undermine the point of the table.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id'], 'idx_auditable');
            $table->index('actor_id');
            $table->index('created_at');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
