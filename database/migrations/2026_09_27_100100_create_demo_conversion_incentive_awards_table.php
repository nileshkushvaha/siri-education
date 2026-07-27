<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One immutable award per converting paid
 * lesson (unique `paid_lesson_id` — the idempotency boundary a
 * duplicate/replayed LessonCompleted event resolves against), linking
 * the qualifying demo, the converting paid lesson, and the resulting
 * InstructorEarning. `rule_snapshot` freezes the rule's configuration
 * at award time so a later admin change to
 * DemoConversionIncentiveSettings never alters a historical award —
 * mirrors InstructorEarning's own calculation-snapshot convention
 * (SRS §15.15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_conversion_incentive_awards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('demo_booking_id')->constrained('bookings');
            $table->foreignUuid('demo_lesson_id')->constrained('lessons');
            $table->foreignUuid('paid_booking_id')->constrained('bookings');
            $table->foreignUuid('paid_lesson_id')->unique()->constrained('lessons');
            $table->foreignId('instructor_id')->constrained('users');
            $table->foreignId('student_id')->constrained('users');
            $table->foreignUuid('instructor_earning_id')->nullable()->constrained('instructor_earnings')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency_code', 3);
            $table->json('rule_snapshot');
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['instructor_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_conversion_incentive_awards');
    }
};
