<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS-7-8 (§7.15 "Resource Library"): a personal, reusable
 * teaching resource an instructor maintains independently of any single
 * homework assignment. subject_id/academic_level_id are optional
 * categorization metadata (search/filter), not an assignment link —
 * reuse across lessons happens via homework_resource_versions +
 * homework_assignment_resources.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_resources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('instructor_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->uuid('academic_level_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('instructor_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->nullOnDelete();
            $table->foreign('academic_level_id')->references('id')->on('academic_levels')->nullOnDelete();

            $table->index(['instructor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_resources');
    }
};
