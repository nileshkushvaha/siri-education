<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14.5 consolidated baseline (originally Phase 14.2) — canonical immutable source records for periodic
 * (daily/weekly/monthly) compensation accrual. One row per agreement +
 * closed period, DB-unique, so scheduled-job retries can never create a
 * duplicate; the resulting instructor_earnings row references this row
 * via source_type='periodic_compensation' / source_id. Never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_compensation_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agreement_id')->constrained('instructor_compensation_agreements');
            $table->foreignId('instructor_id')->constrained('users');
            $table->string('pay_basis', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('timezone', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('currency_id')->nullable()->constrained('currencies');
            $table->string('currency_code', 3);
            $table->timestamp('accrued_at');
            $table->timestamps();

            $table->unique(['agreement_id', 'period_start', 'period_end'], 'icp_agreement_period_unique');
            $table->index(['instructor_id', 'period_start'], 'icp_instructor_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_compensation_periods');
    }
};
