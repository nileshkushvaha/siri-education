<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Optional link to the session it was assigned in/for — homework
            // can exist independently of a specific booking.
            $table->uuid('booking_id')->nullable();
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('student_id');
            $table->string('subject', 100);
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('due_at');
            $table->string('status', 20)->default('pending');
            $table->text('submission_text')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('grade', 20)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
            $table->foreign('teacher_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('users')->cascadeOnDelete();

            $table->index(['student_id', 'status', 'due_at']);
            $table->index(['teacher_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_assignments');
    }
};
