<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_learning_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('learning_goal_id')->constrained('student_learning_goals')->cascadeOnDelete();
            $table->foreignId('primary_instructor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('subject_id');
            $table->uuid('academic_level_id')->nullable();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('initial_assessment')->nullable();
            $table->string('recommended_frequency')->nullable();
            $table->unsignedSmallInteger('recommended_lesson_duration_minutes')->nullable();
            $table->text('preferred_schedule_note')->nullable();
            $table->text('current_focus')->nullable();
            $table->text('current_level_note')->nullable();
            $table->date('target_completion_date')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('subject_id')->references('id')->on('subjects')->restrictOnDelete();
            $table->foreign('academic_level_id')->references('id')->on('academic_levels')->nullOnDelete();
            $table->index(['student_user_id', 'status']);
            $table->index(['primary_instructor_user_id', 'status'], 'learning_plans_instructor_status_index');
            $table->index(['learning_goal_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_learning_plans');
    }
};
