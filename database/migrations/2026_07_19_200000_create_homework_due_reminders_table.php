<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24K — GAP-020 (SRS-7-11, SRS §7.11): durable claim/operation
 * ledger for homework due-date reminders. The composite unique index
 * IS the concurrency guarantee — a scheduler rerun or a second
 * concurrent process racing the same candidate hits a unique-violation
 * on insert and treats it as "already claimed," never a duplicate send.
 *
 * due_at_snapshot is the due_at value observed at claim time, not a
 * live reference: changing an assignment's due date never rewrites
 * old rows (historical reminder evidence stays immutable) and
 * naturally produces a new, legitimate reminder identity for the new
 * due date once its own threshold is reached.
 *
 * restrictOnDelete on both FKs: reminder history must never be
 * silently orphaned by a hard delete of the homework assignment or the
 * recipient user (both are rare/soft-deletable in normal operation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_due_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('homework_assignment_id')->constrained('homework_assignments')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('due_at_snapshot');
            $table->unsignedInteger('reminder_offset_minutes');
            $table->string('status', 20)->default('pending');
            $table->string('failure_category', 60)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('claimed_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['homework_assignment_id', 'recipient_user_id', 'due_at_snapshot', 'reminder_offset_minutes'],
                'homework_due_reminders_identity_unique',
            );
            $table->index(['status', 'due_at_snapshot'], 'homework_due_reminders_status_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_due_reminders');
    }
};
