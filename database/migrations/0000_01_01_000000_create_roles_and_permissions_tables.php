<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA.md sections 1-3 — roles, permissions, role_permission.
 *
 * Roles and permissions are database tables rather than PHP enums. Module 1 of
 * the specification requires managing "users, roles & permissions", and PLAN.md
 * decision 4 makes this explicit: an administrator must be able to adjust access
 * without a code deploy. Hardcoding an enum here would violate Open/Closed.
 *
 * Filename is prefixed 0000_ so this runs before the users table, which carries
 * a foreign key to roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique();   // user | manager | adg | admin
            $table->string('name', 60);
            $table->string('description', 255)->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();   // e.g. availability.check
            $table->string('name', 100);
            $table->string('group', 40)->nullable(); // for grouping in the admin UI
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
