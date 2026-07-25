<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-016 / SRS Chapter 25: one support/dispute case (SRS §25.11
 * "Case Data Fields"). `student_id`/`instructor_id` identify the
 * subject of the case (who the issue is about/for), which is not
 * always `created_by` — an admin may open a case on a student's or
 * instructor's behalf (§25.16). `linked_record_type`/`linked_record_id`
 * is a single optional polymorphic reference (booking, lesson,
 * payment, invoice, wallet ledger entry, withdrawal request, or
 * instructor user) validated server-side by LinkedRecordAuthorizer —
 * never a live join relied on for authorization, only for display.
 *
 * No SoftDeletes column: cases and replies are immutable historical
 * records (§25.32/§Business Rules "no hard-delete"), enforced at the
 * app layer via PreventsHardDeletion, matching Invoice's convention.
 * restrictOnDelete on created_by mirrors invoices.user_id — a support
 * case must never be silently orphaned by a user hard-delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('case_number', 50)->unique();

            $table->string('type', 20);
            $table->string('category', 32);
            $table->string('priority', 16)->default('medium');
            $table->string('status', 20)->default('open');

            $table->unsignedBigInteger('created_by');
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('instructor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->string('linked_record_type', 120)->nullable();
            $table->string('linked_record_id', 60)->nullable();

            $table->string('subject', 255);
            $table->string('description', 4000);

            $table->string('resolution_type', 60)->nullable();
            $table->string('resolution_summary', 2000)->nullable();

            $table->timestamp('opened_at');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('priority');
            $table->index('type');
            $table->index('category');
            $table->index('assigned_to');
            $table->index('created_by');
            $table->index(['linked_record_type', 'linked_record_id']);
            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_cases');
    }
};
