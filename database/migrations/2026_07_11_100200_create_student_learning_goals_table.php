<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_learning_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('subject_id');
            $table->uuid('academic_level_id')->nullable();
            $table->string('title');
            $table->string('type');
            $table->text('description')->nullable();
            $table->date('target_date')->nullable();
            $table->unsignedTinyInteger('priority')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('subject_id')->references('id')->on('subjects')->restrictOnDelete();
            $table->foreign('academic_level_id')->references('id')->on('academic_levels')->nullOnDelete();
            $table->index(['user_id', 'status']);
            $table->index(['subject_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_learning_goals');
    }
};
