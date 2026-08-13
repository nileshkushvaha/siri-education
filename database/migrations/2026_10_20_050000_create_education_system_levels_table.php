<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 3.1 — the exact, student-selectable level within an
        // Education System (e.g. CBSE "Class 10", US "Grade 10", UK
        // "Year 10"). Deliberately a new table, NOT a reuse of
        // education_system_academic_level: that pivot is unique per
        // (education_system_id, academic_level_id) — one row per broad
        // band — whereas several distinct levels here (Class 6..Class 8)
        // legitimately share one AcademicLevel band ("Middle School").
        // No FK is added to academic_levels itself (see
        // docs/architecture/domain-registry.md "do not add
        // academic_levels.education_system_id" — both FKs live on this
        // mapping-style table instead, same pattern as the pivot).
        //
        // normalized_grade bridges back onto the existing universal
        // 1-12 int the booking/matching engine already uses
        // (TeacherSubject::grade_from/grade_to); nullable to allow
        // future non-numeric levels (Undergraduate, Foundation, ...)
        // without forcing a fake int.
        Schema::create('education_system_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('education_system_id');
            $table->uuid('academic_level_id');

            $table->string('value', 50);
            $table->string('display_label', 100);
            $table->unsignedTinyInteger('normalized_grade')->nullable();

            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('education_system_id')->references('id')->on('education_systems')->cascadeOnDelete();
            $table->foreign('academic_level_id')->references('id')->on('academic_levels')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['education_system_id', 'value'], 'education_system_levels_system_value_unique');
            $table->index(['education_system_id', 'is_active'], 'education_system_levels_system_active_index');
            $table->index('academic_level_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_system_levels');
    }
};
