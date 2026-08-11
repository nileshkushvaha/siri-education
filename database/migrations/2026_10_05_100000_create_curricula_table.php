<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Curriculum identity only — never versioned/lifecycle data.
        // Historical integrity lives entirely in curriculum_versions;
        // this row is the stable anchor a version_id ultimately traces
        // back to. Deliberately carries no education_system_id/board_id
        // yet (SRS Book 2 §4.25 defers boards) — a nullable FK can be
        // added later without redesigning this table.
        Schema::create('curricula', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('subject_id');
            $table->uuid('academic_level_id');
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('subject_id')->references('id')->on('subjects')->restrictOnDelete();
            $table->foreign('academic_level_id')->references('id')->on('academic_levels')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Not (subject_id, academic_level_id) alone — multiple
            // curricula may eventually coexist per pair once education
            // systems arrive (SRS §4.25). Name/slug uniqueness within
            // the pair is enough to prevent accidental duplicates today.
            $table->unique(['subject_id', 'academic_level_id', 'slug']);
            $table->index(['subject_id', 'academic_level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curricula');
    }
};
