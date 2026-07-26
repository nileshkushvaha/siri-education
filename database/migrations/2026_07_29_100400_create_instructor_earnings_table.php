<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One earning per canonical source: unique lesson_id for lesson
 * compensation (nullable — periodic
 * compensation carries no lesson) and unique source_type+source_id for
 * every category. Money is integer minor units only — never floats,
 * and amounts come exclusively from compensation agreements: no
 * student-pricing column exists here by design. This table is a
 * platform liability ledger — it never touches student wallets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_earnings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->nullable()->unique()->constrained('lessons')->cascadeOnDelete();
            $table->foreignUuid('booking_id')->nullable()->constrained('bookings');
            $table->foreignId('instructor_id')->constrained('users');
            $table->foreignId('student_id')->nullable()->constrained('users');
            $table->foreignUuid('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->foreignUuid('subject_topic_id')->nullable()->constrained('subject_topics')->nullOnDelete();
            $table->foreignUuid('academic_level_id')->nullable()->constrained('academic_levels')->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('earning_amount_minor');
            $table->string('calculation_type', 16);
            $table->string('status', 32)->default('pending_hold');
            $table->timestamp('hold_until')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignUuid('settlement_batch_id')->nullable()->constrained('instructor_settlement_batches')->nullOnDelete();
            $table->string('source_type', 32)->default('lesson');
            $table->uuid('source_id');
            $table->string('notes', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['instructor_id', 'status']);
            $table->index(['status', 'hold_until']);
            $table->index(['currency_code', 'status']);
            $table->unique(['source_type', 'source_id'], 'ie_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_earnings');
    }
};
