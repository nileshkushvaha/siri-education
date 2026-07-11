<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16A — finance-visible reconciliation queue. `open_dedupe_key`
 * is a STORED generated column (NULL unless status = 'open'), unique-
 * indexed so a concurrent reconciliation run can never create two open
 * issues of the same type for the same withdrawal — the same pattern
 * already used for "single active row" invariants elsewhere in this
 * domain (see instructor_payout_methods / instructor_compensation_agreements).
 * Never hard-deleted; resolution keeps the row, closes the status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_payout_reconciliation_issues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 40)->unique();
            // Explicit short constraint names throughout — the default
            // auto-generated names exceed MySQL's 64-char identifier
            // limit on this table's long name.
            $table->uuid('withdrawal_request_id');
            $table->foreign('withdrawal_request_id', 'ipri_withdrawal_fk')->references('id')->on('instructor_withdrawal_requests');
            $table->uuid('payout_attempt_id')->nullable();
            $table->foreign('payout_attempt_id', 'ipri_attempt_fk')->references('id')->on('instructor_payout_attempts');
            $table->string('provider', 32);
            $table->string('type', 48);
            $table->string('severity', 16)->default('warning');
            $table->string('status', 16)->default('open');
            $table->string('local_status', 20)->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->string('safe_summary', 500);
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->foreign('assigned_to', 'ipri_assigned_to_fk')->references('id')->on('users');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->foreign('resolved_by', 'ipri_resolved_by_fk')->references('id')->on('users');
            $table->string('resolution_type', 32)->nullable();
            $table->string('resolution_note', 1000)->nullable();
            $table->timestamps();

            $table->index('status', 'ipri_status_index');
            $table->index('severity', 'ipri_severity_index');
            $table->index('withdrawal_request_id', 'ipri_withdrawal_index');
            $table->index('payout_attempt_id', 'ipri_attempt_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE instructor_payout_reconciliation_issues
                ADD COLUMN open_dedupe_key VARCHAR(150)
                    GENERATED ALWAYS AS (
                        CASE WHEN status = 'open'
                            THEN CONCAT(withdrawal_request_id, '|', type)
                            ELSE NULL
                        END
                    ) STORED,
                ADD CONSTRAINT ipri_open_dedupe_unique UNIQUE (open_dedupe_key)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_payout_reconciliation_issues');
    }
};
