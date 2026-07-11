<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One lesson per confirmed booking (unique booking_id). The lesson
 * extends the booking lifecycle after confirmation — it snapshots the
 * academic context (subject/topic/level) but never payment data
 * (price, provider ids, wallet details stay on the booking).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users');
            $table->foreignId('instructor_id')->constrained('users');
            $table->foreignUuid('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->foreignUuid('subject_topic_id')->nullable()->constrained('subject_topics')->nullOnDelete();
            // Bookings snapshot a grade int (meta.grade), not a level id —
            // reserved for when level selection reaches the booking flow.
            $table->foreignUuid('academic_level_id')->nullable()->constrained('academic_levels')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 32)->default('scheduled');
            $table->timestamp('student_attended_at')->nullable();
            $table->timestamp('instructor_attended_at')->nullable();
            $table->string('student_attendance_status', 16)->default('pending');
            $table->string('instructor_attendance_status', 16)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('auto_completed_at')->nullable();
            $table->string('completion_notes', 1000)->nullable();
            $table->string('dispute_reason', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'ends_at']);
            $table->index(['instructor_id', 'starts_at']);
            $table->index(['student_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
