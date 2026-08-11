<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Instructor Academic Eligibility (Phase 2): an admin-approved
        // fact that Instructor X may teach Curriculum Y under Education
        // System Z. Anchors to Curriculum identity, never
        // CurriculumVersion — see AcademicContextResolver's class
        // docblock for why "current published version" is resolved
        // dynamically instead. education_system_id is always explicit
        // (never inferred from Curriculum's own mappings) because a
        // system-neutral Curriculum may be approved under more than one
        // Education System independently — see
        // InstructorAcademicEligibilityService's class docblock.
        //
        // No hard delete path: the unique constraint below covers the
        // (teacher, system, curriculum) triple regardless of is_active,
        // so the lifecycle is a single row toggled active/inactive —
        // never re-created — which keeps duplicate prevention correct
        // without needing a deleted_at-aware partial unique index.
        Schema::create('instructor_curriculum_eligibilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('teacher_id');
            $table->uuid('education_system_id');
            $table->uuid('curriculum_id');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('teacher_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('education_system_id')->references('id')->on('education_systems')->cascadeOnDelete();
            $table->foreign('curriculum_id')->references('id')->on('curricula')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['teacher_id', 'education_system_id', 'curriculum_id'], 'instructor_curriculum_eligibility_unique');
            $table->index(['curriculum_id', 'is_active'], 'ice_curriculum_active_idx');
            $table->index(['education_system_id', 'is_active'], 'ice_education_system_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_curriculum_eligibilities');
    }
};
