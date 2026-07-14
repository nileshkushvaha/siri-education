<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17N — durable, deduplicated instructor quality-risk alerts.
 * `detection_fingerprint` is the database-level dedup guard: every
 * detector computes a deterministic fingerprint and relies on this
 * unique index (plus catching the resulting constraint violation) to
 * guarantee "one alert per genuine signal" even under concurrent or
 * replayed event delivery — the same convention
 * `lesson_review_eligibilities`' unique (lesson_id, student_id) index
 * already uses. Nothing here is ever physically deleted; `status` is
 * a guarded state machine, and `needs_reevaluation` is a separate,
 * non-destructive flag so a later-hidden source review can mark its
 * alert stale without erasing history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('instructor_id')->constrained('users');

            $table->string('alert_type', 48);
            $table->string('severity', 16);
            $table->string('status', 32)->default('open');

            $table->string('source_type', 32)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->string('detection_fingerprint', 191)->unique();

            $table->timestamp('triggered_at');
            $table->timestamp('signal_window_start')->nullable();
            $table->timestamp('signal_window_end')->nullable();
            $table->unsignedInteger('signal_count')->nullable();
            $table->json('threshold_snapshot');
            $table->json('summary_metadata')->nullable(); // sanitized evidence references only — never raw text

            $table->boolean('needs_reevaluation')->default(false);
            $table->timestamp('reevaluated_at')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_action', 32)->nullable();
            $table->string('resolution_reason', 500)->nullable();

            $table->unsignedInteger('version')->default(1);

            $table->timestamps();

            $table->index(['instructor_id', 'status']);
            $table->index(['alert_type', 'status']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_alerts');
    }
};
