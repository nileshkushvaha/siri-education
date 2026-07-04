<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a teacher can teach: subject slug + inclusive grade range.
        // null grade bounds = teaches the subject at any grade.
        Schema::create('teacher_subjects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('teacher_id');
            $table->string('subject', 100);
            $table->unsignedTinyInteger('grade_from')->nullable();
            $table->unsignedTinyInteger('grade_to')->nullable();
            $table->timestamps();

            $table->foreign('teacher_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['teacher_id', 'subject', 'grade_from', 'grade_to']);
            $table->index(['subject', 'grade_from', 'grade_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_subjects');
    }
};
