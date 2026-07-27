<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private, lesson-linked instructor-to-student feedback.
 * One row per (lesson, instructor) — a lesson has exactly one assigned
 * instructor, so the composite unique index is the same defence as a
 * plain unique(lesson_id) while staying explicit about what "one
 * feedback per lesson" means. Every observation column is nullable,
 * sanitized plain text — never a numeric or sentiment score, since the
 * SRS defines topics, not a rating scale. `outcome_snapshot` and
 * `source_outcome_version` freeze what was true at submission time so
 * a later outcome override can never silently reinterpret an existing
 * record. Nothing here is ever physically deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_student_feedback', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('instructor_id')->constrained('users');

            $table->string('outcome_snapshot', 32); // finalized LessonOutcome value at submission
            $table->unsignedInteger('source_outcome_version');
            $table->string('attendance_status_snapshot', 32)->nullable(); // finalized student LessonAttendanceStatus at submission — display only, never overwritten here

            $table->text('attendance_observation')->nullable();
            $table->text('preparedness_observation')->nullable();
            $table->text('homework_completion_observation')->nullable();
            $table->text('engagement_observation')->nullable();
            $table->text('learning_attitude_observation')->nullable();
            $table->text('areas_needing_support')->nullable();
            $table->text('private_notes')->nullable();

            $table->json('sanitization_metadata')->nullable(); // {field_key: [flag, ...]} — flags only, never raw text

            $table->timestamp('submitted_at');
            $table->unsignedInteger('version')->default(1);

            $table->timestamps();

            $table->unique(['lesson_id', 'instructor_id'], 'instructor_student_feedback_lesson_instructor_unique');
            $table->index('student_id', 'instructor_student_feedback_student_index');
            $table->index('instructor_id', 'instructor_student_feedback_instructor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_student_feedback');
    }
};
