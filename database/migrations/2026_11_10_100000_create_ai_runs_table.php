<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational record of every AI execution — the audit and cost
 * substrate for P1-P4.
 *
 * WHAT IS NOT HERE IS THE POINT. There is no prompt column, no
 * response column, no input/output payload, no JSON blob that could
 * grow one later. SIRI processes homework, messages and lesson notes
 * belonging to minors; a table that quietly accumulated the text sent
 * to a third-party model would be a second copy of that content,
 * outside the retention and access rules its owning domain enforces,
 * and reachable by anyone with report access. Only identifiers,
 * counters and outcomes live here — enough to answer "what ran, under
 * which prompt version, at what cost, and did it work", which is
 * everything operations and finance actually need.
 *
 * Deliberately NOT the activity log: activity_log is the business audit
 * trail (who did what to which record), while this is infrastructure
 * telemetry written on every provider call, including ones no human
 * triggered. Mixing high-volume machine telemetry into the audit trail
 * would drown the record it exists to protect. Settings changes to the
 * AI module DO still go through AuditTrailService, via the settings
 * page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table): void {
            // One identifier, not an id plus a separate uuid: the
            // project's newer tables (recordings, booking_meetings) use a
            // UUID primary key, and a second surrogate key would only
            // create two ways to name the same row.
            $table->uuid('id')->primary();

            $table->string('feature_key', 64);
            $table->string('provider', 32);
            $table->string('model', 64)->nullable();
            $table->string('prompt_key', 64)->nullable();
            $table->string('prompt_version', 16)->nullable();

            // Free-form morph: the subject may belong to any domain
            // (a review, a lesson, a message) and this module must not
            // grow a foreign key into every one of them. Nullable and
            // unconstrained on purpose — deleting the subject must never
            // block, and an orphaned telemetry row is harmless.
            $table->string('subject_type', 191)->nullable();
            $table->string('subject_id', 64)->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16);
            $table->string('failure_code', 48)->nullable();

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);

            // Estimated, never authoritative: derived from admin-
            // maintained per-model pricing, so it tracks spend for
            // budgeting and never claims to be an invoice.
            $table->decimal('estimated_cost', 12, 6)->default(0);
            $table->char('cost_currency', 3)->default('USD');

            $table->unsignedInteger('latency_ms')->nullable();

            // The provider's own request id — the only thing to quote in
            // a support ticket, and the reason we never need to keep the
            // payload to investigate one.
            $table->string('provider_request_id', 191)->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['feature_key', 'created_at'], 'ai_runs_feature_created_index');
            $table->index(['status', 'created_at'], 'ai_runs_status_created_index');
            $table->index(['subject_type', 'subject_id'], 'ai_runs_subject_index');
            // Budget guard reads a date-bounded cost sum on every run.
            $table->index('created_at', 'ai_runs_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
