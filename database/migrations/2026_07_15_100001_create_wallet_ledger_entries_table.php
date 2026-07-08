<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only financial ledger. A posted row is never updated except
 * for the reversal fields (status/reversed_at) — a reversal is always
 * a new offsetting entry, never a mutation of history. amount_minor is
 * an unsigned magnitude; `direction` carries the sign. idempotency_key
 * is unique so a retried credit/debit request can never double-apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('wallet_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('currency_id');
            $table->char('currency_code', 3);

            $table->string('direction', 10);
            $table->string('entry_type', 30);
            $table->bigInteger('amount_minor');

            $table->bigInteger('balance_after_minor');
            $table->bigInteger('available_after_minor');
            $table->bigInteger('held_after_minor');

            $table->string('status', 20);

            $table->string('source_type', 50)->nullable();
            $table->string('source_id', 60)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('wallet_id')->references('id')->on('wallets')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique('idempotency_key');
            $table->index(['wallet_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
            $table->index('status');
            $table->index('entry_type');
        });

        DB::statement('ALTER TABLE wallet_ledger_entries ADD CONSTRAINT chk_wallet_ledger_amount_positive CHECK (amount_minor >= 0)');
        DB::statement("ALTER TABLE wallet_ledger_entries ADD CONSTRAINT chk_wallet_ledger_direction CHECK (direction IN ('credit', 'debit'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
    }
};
