<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin queue of unresolved compensation exceptions: completed
 * lessons whose earning creation was blocked (missing/invalid
 * agreement, invalid currency, unsupported duration, transient failure,
 * or permanently ineligible). One open row per lesson (unique), updated
 * in place on every attempt, marked resolved when the earning finally
 * exists. Reasons are UI-safe — no student pricing, no internals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_compensation_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->unique()->constrained('lessons');
            $table->foreignUuid('booking_id')->nullable()->constrained('bookings');
            $table->foreignId('instructor_id')->constrained('users');
            $table->timestamp('scheduled_start_at');
            $table->string('category', 32);
            $table->string('reason', 500);
            $table->boolean('retry_eligible')->default(true);
            $table->unsignedInteger('attempt_count')->default(1);
            $table->timestamp('first_failed_at');
            $table->timestamp('last_attempt_at');
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('resolved_earning_id')->nullable()->constrained('instructor_earnings');
            $table->timestamp('retry_exhausted_at')->nullable();
            $table->timestamps();

            $table->index(['resolved_at', 'retry_eligible'], 'ice_open_retryable_index');
            $table->index('next_retry_at');
            $table->index('category');
            $table->index('instructor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_compensation_exceptions');
    }
};
