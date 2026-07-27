<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §16.17-§16.19: one immutable row per issued
 * promotional credit, whether campaign-attributed or ad-hoc manual —
 * never updated, never deleted (PreventsHardDeletion + PreventsUpdates
 * on the model, mirroring support_case_replies' immutability
 * convention). `idempotency_key` is unique and shared with the
 * `wallet_ledger_entries` row it produced — the actual database-backed
 * duplicate-prevention guarantee, not merely an application check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotional_credit_issuances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('campaign_id')->nullable()->constrained('promotional_credit_campaigns')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('wallet_ledger_entry_id')->constrained('wallet_ledger_entries')->restrictOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);

            $table->string('issuance_type', 20);
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);

            $table->string('idempotency_key', 150)->unique();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->index(['campaign_id', 'student_id']);
            $table->index('student_id');
        });

        DB::statement('ALTER TABLE promotional_credit_issuances ADD CONSTRAINT chk_promo_issuances_amount_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE promotional_credit_issuances ADD CONSTRAINT chk_promo_issuances_type CHECK (issuance_type IN ('campaign', 'manual'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('promotional_credit_issuances');
    }
};
