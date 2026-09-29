<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA.md section 13 — settings.
 *
 * Brought forward from Phase 9 because the Gmail OAuth refresh token needs a
 * persistent, encrypted home. A refresh token is a long-lived credential: it
 * cannot live in .env (it is minted at runtime, after an interactive consent)
 * and it must not sit in plain text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->enum('type', ['STRING', 'INT', 'BOOL', 'JSON', 'SECRET'])->default('STRING');
            $table->string('group', 40)->default('general');
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
