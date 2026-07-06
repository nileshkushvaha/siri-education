<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_plan_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learning_plan_id')->constrained('student_learning_plans')->cascadeOnDelete();
            $table->foreignId('student_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('instructor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('review_number');
            $table->text('summary')->nullable();
            $table->text('progress_notes')->nullable();
            $table->text('challenges')->nullable();
            $table->text('recommendations')->nullable();
            $table->text('homework_quality_note')->nullable();
            $table->text('attendance_note')->nullable();
            $table->text('next_focus')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['learning_plan_id', 'review_number']);
            $table->index(['student_user_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_plan_reviews');
    }
};
