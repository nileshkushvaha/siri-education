<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4B.3 — reconciliation bookkeeping for generic payment attempts.
 *
 * The sweep needs to know when an attempt was last polled, otherwise it
 * re-queries the provider for the same stuck attempt on every run
 * forever — wasted calls and a needless rate-limit risk. This mirrors
 * `wallet_recharges.last_synced_at` exactly, including its role in the
 * "due for reconciliation" scope.
 *
 * Deliberately the ONLY schema change this phase needs: settlement
 * itself introduces no new state, because payment/purchase/entitlement
 * commit together or not at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('last_synced_at')->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('last_synced_at');
        });
    }
};
