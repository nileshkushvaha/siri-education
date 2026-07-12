<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16C — collection-side mirror of instructor_payout_reconciliation_issues
 * (see that table's migration for the STORED-generated-column dedupe
 * reasoning). FK is to booking_payments, not a withdrawal/attempt pair
 * — the collection domain has one attempt-level row per gateway
 * payment attempt, not a separate attempt table. Never hard-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_payment_reconciliation_issues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 40)->unique();
            $table->uuid('booking_payment_id');
            $table->foreign('booking_payment_id', 'bpri_booking_payment_fk')->references('id')->on('booking_payments');
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
            $table->foreign('assigned_to', 'bpri_assigned_to_fk')->references('id')->on('users');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->foreign('resolved_by', 'bpri_resolved_by_fk')->references('id')->on('users');
            $table->string('resolution_type', 32)->nullable();
            $table->string('resolution_note', 1000)->nullable();
            $table->timestamps();

            $table->index('status', 'bpri_status_index');
            $table->index('severity', 'bpri_severity_index');
            $table->index('booking_payment_id', 'bpri_booking_payment_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE booking_payment_reconciliation_issues
                ADD COLUMN open_dedupe_key VARCHAR(150)
                    GENERATED ALWAYS AS (
                        CASE WHEN status = 'open'
                            THEN CONCAT(booking_payment_id, '|', type)
                            ELSE NULL
                        END
                    ) STORED,
                ADD CONSTRAINT bpri_open_dedupe_unique UNIQUE (open_dedupe_key)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payment_reconciliation_issues');
    }
};
