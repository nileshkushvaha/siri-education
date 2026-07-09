<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10.2F — optional instructor-specific student price override.
 * `instructor_id` NULL means the base price (all instructors);
 * `instructor_id` set means a special student-facing price for that
 * instructor only — resolved with higher priority than the base price
 * (see StudentLessonPriceResolver). This is a student-facing amount
 * override, not instructor compensation — no instructor payout/earning
 * concept is introduced by this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_lesson_prices', function (Blueprint $table): void {
            $table->foreignId('instructor_id')
                ->nullable()
                ->after('booking_type_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(
                ['booking_type_id', 'subject_id', 'country_id', 'duration_minutes', 'academic_level_id', 'instructor_id'],
                'idx_student_lesson_prices_instructor_match',
            );
        });
    }

    public function down(): void
    {
        Schema::table('student_lesson_prices', function (Blueprint $table): void {
            $table->dropIndex('idx_student_lesson_prices_instructor_match');
            $table->dropConstrainedForeignId('instructor_id');
        });
    }
};
