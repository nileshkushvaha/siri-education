<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4D — the instructor's structured academic SELECTION on the
 * proposal itself.
 *
 * These two columns are not a second copy of the academic snapshot.
 * They are the durable record of what the instructor picked, so
 * submit() can RE-RESOLVE the authoritative context from stable ids
 * rather than trusting whatever the browser still had in memory (spec
 * §13 "resolve twice", §11 "never trust an earlier Livewire preview").
 * The resolved, immutable result of that second resolve is written to
 * `package_academic_contexts` — that table remains the single
 * authoritative academic truth for a package.
 *
 * `academic_level_id` (pre-existing) is no longer an instructor choice
 * once the structured flow is in effect: it is DERIVED from
 * education_system_level_id → EducationSystemLevel::academicLevel, and
 * written from the frozen snapshot, so the legacy column and the
 * snapshot can only ever agree (spec §2 "Do not create two competing
 * academic truths").
 *
 * Both nullable: a proposal created while the packages feature is off
 * for the student's country keeps the legacy Subject+AcademicLevel
 * shape and is deliberately ineligible for the structured
 * package-funded booking path (spec §35/§36 — fail closed, never fuzzy
 * match).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructor_package_proposals', function (Blueprint $table): void {
            $table->uuid('education_system_id')->nullable()->after('academic_level_id');
            $table->uuid('education_system_level_id')->nullable()->after('education_system_id');

            $table->foreign('education_system_id')->references('id')->on('education_systems')->nullOnDelete();
            $table->foreign('education_system_level_id')->references('id')->on('education_system_levels')->nullOnDelete();

            $table->index('education_system_id');
            $table->index('education_system_level_id');
        });
    }

    public function down(): void
    {
        Schema::table('instructor_package_proposals', function (Blueprint $table): void {
            $table->dropForeign(['education_system_id']);
            $table->dropForeign(['education_system_level_id']);
            $table->dropIndex(['education_system_id']);
            $table->dropIndex(['education_system_level_id']);
            $table->dropColumn(['education_system_id', 'education_system_level_id']);
        });
    }
};
