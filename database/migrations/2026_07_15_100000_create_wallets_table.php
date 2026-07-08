<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One wallet per user per currency. Money is stored only as integer
 * minor units (paise/cents) — never a float/decimal column — so no
 * floating-point rounding can ever enter a balance. `status = closed`
 * is the terminal state instead of soft-deleting a wallet: a wallet
 * with historical ledger entries must never actually disappear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('currency_id');
            $table->char('currency_code', 3);

            $table->bigInteger('balance_minor')->default(0);
            $table->bigInteger('available_balance_minor')->default(0);
            $table->bigInteger('held_balance_minor')->default(0);

            $table->string('status', 20)->default('active');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // One wallet per user per currency — the DB is the final guard,
            // not just WalletService::getOrCreateWallet()'s own check.
            $table->unique(['user_id', 'currency_id']);
            $table->index('status');
        });

        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_wallets_balance_split CHECK (balance_minor = available_balance_minor + held_balance_minor)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_wallets_non_negative CHECK (available_balance_minor >= 0 AND held_balance_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
