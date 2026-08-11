<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // EducationSystem <-> AcademicLevel is many-to-many: the same
        // AcademicLevel row (e.g. "High School") may participate in
        // more than one Education System, and a system spans several
        // levels. Deliberately not academic_levels.education_system_id
        // (see docs/architecture/domain-registry.md).
        Schema::create('education_system_academic_level', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('education_system_id');
            $table->uuid('academic_level_id');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('education_system_id')->references('id')->on('education_systems')->cascadeOnDelete();
            $table->foreign('academic_level_id')->references('id')->on('academic_levels')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['education_system_id', 'academic_level_id'], 'education_system_academic_level_unique');
            $table->index(['education_system_id', 'is_active'], 'education_system_academic_level_system_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_system_academic_level');
    }
};
