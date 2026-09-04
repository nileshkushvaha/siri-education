<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two more per-currency wallet rules, alongside the recharge limits that
 * already live here (SRS §13.12 / §13.16):
 *
 * - low_balance_threshold_minor: balance below which the student sees a
 *   low-balance alert. Replaces the currency-blind wallet.low_balance_threshold
 *   setting, which could not mean the same thing in nine currencies.
 * - recharge_multiple_minor: recharge amounts must be a whole multiple of
 *   this (client rule: "amounts in steps of 10"). Seeded as 10 major units
 *   for every currency; NULL means no step rule.
 *
 * Integer minor units in each currency's own exponent, never floats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table): void {
            $table->unsignedBigInteger('low_balance_threshold_minor')->nullable()->after('maximum_recharge_minor');
            $table->unsignedBigInteger('recharge_multiple_minor')->nullable()->after('low_balance_threshold_minor');
        });

        foreach (DB::table('currencies')->get(['id', 'code', 'minor_units']) as $currency) {
            $unit = 10 ** (int) $currency->minor_units;

            DB::table('currencies')->where('id', $currency->id)->update([
                'recharge_multiple_minor' => 10 * $unit,
                'low_balance_threshold_minor' => match ($currency->code) {
                    'INR' => 500 * $unit,
                    'USD', 'GBP' => 10 * $unit,
                    default => null,
                },
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table): void {
            $table->dropColumn(['low_balance_threshold_minor', 'recharge_multiple_minor']);
        });
    }
};
