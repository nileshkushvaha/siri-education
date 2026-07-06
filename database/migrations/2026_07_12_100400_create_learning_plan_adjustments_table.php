<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_plan_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learning_plan_id')->constrained('student_learning_plans')->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_reason');
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['learning_plan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_plan_adjustments');
    }
};
