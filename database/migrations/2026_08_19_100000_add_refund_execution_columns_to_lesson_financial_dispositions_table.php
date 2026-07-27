<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A disposition that produced a wallet refund stores the
 * resulting immutable ledger entry — the linkage that makes duplicate
 * execution detectable and override-after-refund reconciliation
 * traceable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_financial_dispositions', function (Blueprint $table): void {
            $table->foreignUuid('refund_ledger_entry_id')
                ->nullable()
                ->after('payment_reference')
                ->constrained('wallet_ledger_entries')
                ->nullOnDelete();
            $table->timestamp('refund_executed_at')->nullable()->after('refund_ledger_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_financial_dispositions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('refund_ledger_entry_id');
            $table->dropColumn('refund_executed_at');
        });
    }
};
