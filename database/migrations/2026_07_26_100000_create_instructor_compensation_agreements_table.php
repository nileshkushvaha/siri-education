<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14.2 — effective-dated, admin-managed instructor compensation
 * agreements. Compensation is decided internally per instructor
 * (hourly/daily/weekly/monthly, integer minor units) and is never
 * derived from student-facing pricing. Financial terms are immutable
 * once active — changes create a replacement agreement (version chain
 * via supersedes_agreement_id). Never hard-deleted.
 *
 * A STORED generated column + unique index caps active agreements at
 * one per instructor at the database level (MySQL permits unlimited
 * NULLs under a unique index), backstopping the service's owner-row
 * locking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_compensation_agreements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 32)->unique();
            $table->foreignId('instructor_id')->constrained('users');
            $table->string('pay_basis', 16);
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('currency_id')->nullable()->constrained('currencies');
            $table->string('currency_code', 3);
            $table->string('timezone', 64);
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->uuid('supersedes_agreement_id')->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('internal_reason', 1000);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->foreign('supersedes_agreement_id', 'ica_supersedes_foreign')
                ->references('id')->on('instructor_compensation_agreements');

            $table->index(['instructor_id', 'status'], 'ica_instructor_status_index');
            $table->index('status');
            $table->index('effective_from');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE instructor_compensation_agreements
                ADD COLUMN active_owner_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (
                        CASE WHEN status = 'active' THEN instructor_id ELSE NULL END
                    ) STORED,
                ADD UNIQUE INDEX ica_active_owner_unique (active_owner_key)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_compensation_agreements');
    }
};
