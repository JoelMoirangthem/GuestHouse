<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCHEMA.md section 13 — `holidays`, the institute's holiday calendar.
 *
 * `year` is stored rather than derived so the calendar can be listed and
 * copied per year with a plain index lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 120);
            $table->enum('type', ['PUBLIC', 'RESTRICTED', 'LOCAL'])->default('PUBLIC');
            $table->unsignedSmallInteger('year');
            $table->timestamps();

            $table->index('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
