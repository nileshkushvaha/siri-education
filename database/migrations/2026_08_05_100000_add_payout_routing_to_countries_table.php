<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A country's INSTRUCTOR PAYOUT provider route, stored separately from
 * `payment_routing` (the STUDENT COLLECTION route on
 * the same table). Deliberately a distinct column, not a shared one:
 * these are two independent routing decisions that must never be
 * coupled just because they happen to key off the same Country row —
 * see docs/payment-collection-and-payout-provider-routing.md §8.
 * Shape mirrors `payment_routing`: {"provider": "razorpayx", "enabled": true}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->json('payout_routing')->nullable()->after('payment_routing');
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->dropColumn('payout_routing');
        });
    }
};
