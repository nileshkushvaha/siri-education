<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 3 / 3.1 — immutable, per-Booking academic-context
        // snapshot. Historical display values are denormalized alongside
        // the stable master ids so a later rename/republish of any
        // academic master (including EducationSystemLevel's own label)
        // never rewrites what THIS booking historically represented (see
        // BookingAcademicContext model docblock).
        //
        // booking_id mirrors booking_meetings' convention exactly: one
        // historical child per Booking, restrictOnDelete (never cascade)
        // — meeting/academic history is booking history and is never
        // silently wiped by a booking-row operation. Academic master FK
        // columns use nullOnDelete (never cascade, never restrict) —
        // an archived/renamed Education System/Curriculum/etc. must
        // never block or cascade-delete historical booking data; the
        // denormalized display columns remain the historical record of
        // record even if the id link is later nulled.
        //
        // Phase 3.1: academic_level_grade (a hardcoded "Grade %d"
        // string) is replaced by education_system_level_id plus the
        // denormalized level_term/level_value/level_display/
        // normalized_grade — the student-facing "Class 10"/"Grade 10"/
        // "Year 10" selection, decoupled from any hardcoded terminology.
        Schema::create('booking_academic_contexts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('booking_id')->unique();

            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('country_name')->nullable();

            $table->uuid('education_system_id')->nullable();
            $table->string('education_system_code', 50)->nullable();
            $table->string('education_system_name')->nullable();

            $table->uuid('academic_level_id')->nullable();
            $table->string('academic_level_name')->nullable();

            $table->uuid('education_system_level_id')->nullable();
            $table->string('level_term', 50)->nullable();
            $table->string('level_value', 50)->nullable();
            $table->string('level_display', 100)->nullable();
            $table->unsignedTinyInteger('normalized_grade')->nullable();

            $table->uuid('subject_id')->nullable();
            $table->string('subject_name')->nullable();
            $table->string('subject_slug')->nullable();

            $table->uuid('curriculum_id')->nullable();
            $table->string('curriculum_name')->nullable();
            $table->string('curriculum_slug')->nullable();

            $table->uuid('curriculum_version_id')->nullable();
            $table->unsignedInteger('curriculum_version_number')->nullable();

            $table->timestamps();

            $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();
            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            $table->foreign('education_system_id')->references('id')->on('education_systems')->nullOnDelete();
            $table->foreign('academic_level_id')->references('id')->on('academic_levels')->nullOnDelete();
            $table->foreign('education_system_level_id')->references('id')->on('education_system_levels')->nullOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->nullOnDelete();
            $table->foreign('curriculum_id')->references('id')->on('curricula')->nullOnDelete();
            $table->foreign('curriculum_version_id')->references('id')->on('curriculum_versions')->nullOnDelete();

            $table->index('country_id');
            $table->index('education_system_id');
            $table->index('academic_level_id');
            $table->index('education_system_level_id');
            $table->index('subject_id');
            $table->index('curriculum_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_academic_contexts');
    }
};
