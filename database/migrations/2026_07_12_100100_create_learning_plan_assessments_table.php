<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_plan_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learning_plan_id')->constrained('student_learning_plans')->cascadeOnDelete();
            $table->foreignId('student_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('instructor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assessment_type');
            $table->text('strengths')->nullable();
            $table->text('weaknesses')->nullable();
            $table->string('current_level')->nullable();
            $table->string('learning_pace')->nullable();
            $table->text('recommended_focus')->nullable();
            $table->string('recommended_frequency')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['learning_plan_id', 'assessment_type']);
            $table->index(['student_user_id', 'assessment_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_plan_assessments');
    }
};
