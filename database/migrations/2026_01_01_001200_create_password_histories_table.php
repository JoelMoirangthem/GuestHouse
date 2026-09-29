<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `password_histories` — SECURITY.md section 3: "no reuse of last 3".
 *
 * Holds bcrypt hashes only, exactly as strong as the users.password column.
 * Existing users are seeded with their current hash so the rule applies from
 * the first change after deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('password');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
        });

        DB::statement('INSERT INTO password_histories (user_id, password, created_at) SELECT id, password, NOW() FROM users');
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
    }
};
