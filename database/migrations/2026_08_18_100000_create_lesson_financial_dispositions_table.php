<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17E — the idempotent financial-decision bridge: exactly one
 * disposition record per lesson (unique lesson_id). Re-evaluations
 * (outcome overrides) push the previous snapshot into `history` and
 * bump `version` — records are never deleted, and this table never
 * moves money: it classifies what the (future) execution phase and the
 * existing wallet/earning/cancellation pipelines must do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_financial_dispositions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->unique()->constrained('lessons')->cascadeOnDelete();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();

            $table->string('outcome', 32); // finalized LessonOutcome snapshot
            $table->string('student_disposition', 48);
            $table->string('instructor_disposition', 48);
            $table->string('processing_status', 32)->default('pending');
            $table->string('reason_code', 64)->nullable();

            $table->foreignUuid('instructor_earning_id')->nullable()->constrained('instructor_earnings')->nullOnDelete();
            $table->string('payment_reference')->nullable(); // booking payment snapshot, for the execution phase

            $table->boolean('admin_hold')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->json('history')->nullable(); // previous snapshots, appended on re-evaluation

            $table->timestamp('evaluated_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['processing_status', 'evaluated_at'], 'lesson_financial_dispositions_status_evaluated_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_financial_dispositions');
    }
};
