<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §17.28-§17.36: one conversation per (student,
 * instructor, context) triple. `context_type`/`context_id` is a
 * single polymorphic reference restricted to Booking or
 * StudentLearningPlan (§17.30's narrower V1 set — Homework/Lesson/
 * Support-case context are not separate conversation anchors this
 * phase; a lesson-derived eligibility source resolves to its owning
 * booking). `context_id` is a string(60) to hold either Booking's
 * uuid or StudentLearningPlan's bigint id, matching the established
 * convention on invoices.source_id/support_cases.linked_record_id.
 *
 * The unique index is the actual "no duplicate conversations for the
 * same participants and context" guarantee (requirement #2) — the
 * service's find-or-create is a courtesy, not the enforcement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();

            $table->string('context_type', 120);
            $table->string('context_id', 60);

            $table->string('status', 20)->default('active');

            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['student_id', 'instructor_id', 'context_type', 'context_id'], 'conversations_participants_context_unique');
            $table->index('status');
            $table->index('student_id');
            $table->index('instructor_id');
            $table->index(['context_type', 'context_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
