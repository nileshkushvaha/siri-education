<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §26.27-§26.29 — durable operational alerts,
 * replacing the generic activity→notify pipeline's blanket, unrouted,
 * super-admin-only coverage for operational failures. A row is never
 * deleted (PreventsHardDeletion on the model); history accumulates
 * forever, exactly mirroring `suspicious_activity_flags`'s
 * fingerprint/active_fingerprint dedup shape — a *different* domain
 * (operational failure, never suspected fraud), same proven pattern.
 *
 * `active_fingerprint` is unique and null while the alert is terminal
 * (Resolved) — a recurrence after resolution starts a fresh row (a new
 * episode) rather than reopening the closed one, identical to the
 * compliance-flag precedent.
 *
 * `subject_type`/`subject_id` are a lightweight polymorphic reference
 * (never a real relation/FK — alerts can point at a Booking, a
 * reconciliation issue, or nothing at all), so `subject_id` is a plain
 * string wide enough for both integer and UUID primary keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 40)->unique();

            $table->string('type', 64);
            $table->string('category', 32);
            $table->string('severity', 16);
            $table->string('status', 16)->default('open');

            $table->string('title', 191);
            $table->text('summary');

            $table->string('subject_type', 191)->nullable();
            $table->string('subject_id', 64)->nullable();

            $table->string('fingerprint', 191);
            $table->string('active_fingerprint', 191)->nullable()->unique();

            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');

            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_reason', 500)->nullable();

            $table->json('metadata');

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['category', 'status']);
            $table->index(['type', 'status']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_alerts');
    }
};
