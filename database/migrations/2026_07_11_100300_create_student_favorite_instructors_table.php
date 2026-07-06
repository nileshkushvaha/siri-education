<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_favorite_instructors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('instructor_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['student_user_id', 'instructor_user_id'], 'student_favorite_instructors_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_favorite_instructors');
    }
};
