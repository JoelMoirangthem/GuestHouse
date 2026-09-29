<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA.md section 13 — notifications and email_templates.
 *
 * Every attempted delivery is recorded as a row, whatever the channel. That
 * matters for two reasons: the in-app bell reads from this table directly, and a
 * failed email becomes visible evidence rather than a silent loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('booking_request_id')->nullable()->constrained('booking_requests')->nullOnDelete();

            $table->string('event_key', 60);            // request.approved.adg, ...
            $table->enum('channel', ['EMAIL', 'SMS', 'IN_APP']);

            $table->string('title', 160);
            $table->text('body');

            // A deep link so the recipient can act, not merely be informed.
            $table->string('action_url', 255)->nullable();

            $table->enum('status', ['PENDING', 'SENT', 'FAILED', 'READ'])->default('PENDING');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // Serves the bell's unread query, which runs on every poll.
            $table->index(['user_id', 'channel', 'read_at'], 'idx_bell');
            $table->index(['user_id', 'status']);
            $table->index('event_key');
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 60)->unique();

            $table->string('subject', 200);
            $table->text('body_html');
            $table->text('body_text')->nullable();

            // Kept even though SMS is disabled, so enabling a gateway later needs
            // no schema change.
            $table->string('sms_text', 320)->nullable();

            $table->json('placeholders')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('notifications');
    }
};
