<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Explicit topic-level coverage: which parts of a subject an
        // instructor teaches, optionally scoped to an academic level
        // (null = all levels). Complements teacher_subjects (whole-subject
        // + grade range, still the booking baseline) — a row here is
        // required only when a student books a specific topic.
        Schema::create('instructor_subject_topics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('teacher_id');
            $table->uuid('subject_id');
            $table->uuid('subject_topic_id');
            $table->uuid('academic_level_id')->nullable();
            $table->string('proficiency_level', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            // Bookable/marketplace-visible only when approved — explicit
            // coverage is an enterprise matching rule, not self-service.
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();

            $table->foreign('teacher_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->cascadeOnDelete();
            $table->foreign('subject_topic_id')->references('id')->on('subject_topics')->cascadeOnDelete();
            $table->foreign('academic_level_id')->references('id')->on('academic_levels')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['teacher_id', 'subject_topic_id', 'academic_level_id'], 'instructor_topic_level_unique');
            $table->index(['subject_topic_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_subject_topics');
    }
};
