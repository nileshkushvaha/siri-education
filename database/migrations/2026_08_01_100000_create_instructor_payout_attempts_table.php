<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per logical payout-execution attempt against an approved
 * withdrawal. `execution_sequence` numbers successive attempts
 * for the same withdrawal (a fresh attempt only ever follows a
 * terminal, pre-acceptance failure); `idempotency_key` +
 * `request_fingerprint` make retrying the SAME logical execution safe.
 * Never hard-deleted — a failed/cancelled attempt stays visible
 * history. No bank details are stored here; the payout destination is
 * read once, at call time, from the withdrawal's own encrypted
 * snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_payout_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 40)->unique();
            $table->foreignUuid('withdrawal_request_id')->constrained('instructor_withdrawal_requests');
            $table->foreignId('instructor_id')->constrained('users');
            $table->string('provider', 32);
            $table->unsignedTinyInteger('execution_sequence');
            $table->uuid('idempotency_key');
            $table->string('request_fingerprint', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('currency_id')->nullable()->constrained('currencies');
            $table->string('currency_code', 3);
            $table->string('status', 16)->default('created');
            $table->string('provider_payout_id', 80)->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->string('provider_status_reason', 500)->nullable();
            $table->string('provider_response_code', 20)->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('provider_processed_at')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->foreignId('initiated_by')->constrained('users');
            $table->string('failure_category', 48)->nullable();
            $table->string('safe_failure_message', 500)->nullable();
            $table->longText('encrypted_provider_metadata')->nullable();
            // Test/dev-only fake-provider control — never fillable, never
            // exposed in any form; a real adapter ignores it entirely.
            $table->string('requested_fake_scenario', 40)->nullable();
            $table->timestamps();

            $table->unique(['withdrawal_request_id', 'execution_sequence'], 'ipa_withdrawal_sequence_unique');
            $table->unique(['provider', 'idempotency_key'], 'ipa_provider_idempotency_unique');
            $table->index(['provider', 'provider_payout_id'], 'ipa_provider_payout_id_index');
            $table->index('status');
            $table->index('provider_status');
            $table->index('next_retry_at');
            $table->index('withdrawal_request_id');
        });

        // MySQL InnoDB unique indexes already permit unlimited NULLs, so a
        // plain unique index is sufficient here (unlike the generated-
        // column trick used for "single active row" invariants elsewhere).
        DB::statement(<<<'SQL'
            ALTER TABLE instructor_payout_attempts
                ADD CONSTRAINT ipa_provider_payout_id_unique UNIQUE (provider, provider_payout_id),
                ADD CONSTRAINT chk_ipa_amount_positive CHECK (amount_minor > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_payout_attempts');
    }
};
