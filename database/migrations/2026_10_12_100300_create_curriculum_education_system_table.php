<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Curriculum <-> EducationSystem applicability. Curriculum
        // identity (subject_id + academic_level_id) established in
        // Phase 0A is NOT redesigned here — this is an additive
        // mapping, not a mandatory education_system_id column. A
        // Curriculum with zero rows here is globally applicable
        // (system-neutral / legacy-compatible) — see
        // AcademicContextResolver and docs/architecture/domain-registry.md.
        Schema::create('curriculum_education_system', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('curriculum_id');
            $table->uuid('education_system_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('curriculum_id')->references('id')->on('curricula')->cascadeOnDelete();
            $table->foreign('education_system_id')->references('id')->on('education_systems')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['curriculum_id', 'education_system_id'], 'curriculum_education_system_unique');
            $table->index('education_system_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_education_system');
    }
};
