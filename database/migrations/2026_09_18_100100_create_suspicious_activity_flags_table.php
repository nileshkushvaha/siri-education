<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 30 (GAP-014/GAP-015) — rule-based compliance monitoring. A row
 * is evidence for human review, never a sanction; nothing here ever
 * cascades into suspension, payment blocking, or wallet freezing.
 *
 * `active_fingerprint` is the concurrency-safe dedup guard, mirroring
 * `instructor_waitlist_entries.active_key`: it equals `fingerprint`
 * while status is Open/InReview (unique — MySQL allows unlimited
 * NULLs on a nullable unique column) and is set to NULL on transition
 * to Resolved/Dismissed, so at most one ACTIVE flag can ever exist per
 * fingerprint while unlimited terminal (historical) rows accumulate
 * freely. `fingerprint` itself (not unique) is preserved on every row
 * so the full history for one subject+rule can always be queried.
 *
 * `occurrence_count`/`last_observed_at` are mutable — unlike
 * `quality_alerts.signal_count`, a repeat trigger against an already-
 * active flag increments these in place ("merging", not just
 * insert-or-ignore) — but `evidence` and `threshold_snapshot` capture
 * point-in-time state and original rule configuration respectively;
 * neither is ever deleted or overwritten with unsafe raw data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suspicious_activity_flags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 40)->unique();

            $table->string('rule_code', 64);
            $table->unsignedInteger('rule_version')->default(1);
            $table->string('category', 32);
            $table->string('severity', 16);
            $table->string('status', 16)->default('open');

            $table->foreignId('subject_id')->constrained('users');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_observed_at');
            $table->timestamp('last_observed_at');

            $table->json('evidence');
            $table->json('threshold_snapshot');

            $table->string('fingerprint', 191);
            $table->string('active_fingerprint', 191)->nullable()->unique();

            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 32)->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['subject_id', 'status']);
            $table->index(['rule_code', 'status']);
            $table->index(['category', 'status']);
            $table->index(['severity', 'status']);
            $table->index(['fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspicious_activity_flags');
    }
};
