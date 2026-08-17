<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §13.12 — "Minimum recharge amount / Maximum recharge amount /
 * Country-specific recharge limits / Currency-specific recharge
 * precision".
 *
 * WHY HERE AND NOT IN WalletSettings. The limits previously lived as
 * two platform-wide floats (`wallet.minimum_recharge_amount`,
 * `wallet.maximum_recharge_amount`) which WalletRechargeService applied
 * to EVERY currency by re-expressing the same number in each currency's
 * own minor units. The seeded default of 100/50000 therefore meant a
 * ₹100 floor in India and a $100 floor in the United States — the same
 * numeral silently reinterpreted as nine different amounts of money.
 * There is no exchange rate anywhere in this application (SRS §13.7
 * forbids cross-currency wallet operations outright), so a single
 * scalar CANNOT express a limit that is meaningful in more than one
 * currency. The old shape was not under-configured, it was unsound.
 *
 * A recharge limit is a quantity of money, and every quantity of money
 * in this schema is stored as integer minor units alongside the
 * currency that denominates it. `currencies` already owns
 * `minor_units`, the exponent those limits must be expressed in, and
 * SRS §13.7 makes wallet currency a function of the student's billing
 * country — so per-currency limits also satisfy §13.12's
 * "country-specific" requirement without a second country-keyed table
 * that could disagree with the country→currency mapping.
 *
 * NULLABLE MEANS UNCONFIGURED, NOT ZERO. NULL minimum = no floor
 * beyond the universal "amount > 0"; NULL maximum = no ceiling beyond
 * the provider's own technical limits. Only the three minimums SRS
 * §13.12 states explicitly (INR 500, USD 10, GBP 10) are set; no
 * maximum is set for any currency and no figure is invented for the
 * remaining markets, because inventing a plausible-looking limit is
 * indistinguishable, later, from a limit the business actually chose.
 *
 * The backfill below only reaches databases whose currency rows already
 * exist. On a FRESH install seeders run after migrations, so
 * CurrencySeeder carries the same SRS figures for newly created rows —
 * that is the authoritative copy, and it deliberately never overwrites
 * a limit an operator has since edited.
 */
return new class extends Migration
{
    /** SRS §13.12's own worked example — the only recharge limits the specification states. */
    private const array SRS_MINIMUMS = [
        'INR' => 50000,
        'USD' => 1000,
        'GBP' => 1000,
    ];

    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table): void {
            $table->unsignedBigInteger('minimum_recharge_minor')->nullable()->after('minor_units');
            $table->unsignedBigInteger('maximum_recharge_minor')->nullable()->after('minimum_recharge_minor');
        });

        // A maximum below the minimum would silently make every recharge
        // impossible in that currency. The database refuses the
        // combination outright rather than leaving it to the admin form.
        DB::statement(<<<'SQL'
            ALTER TABLE currencies
            ADD CONSTRAINT chk_currencies_recharge_limit_order
            CHECK (
                minimum_recharge_minor IS NULL
                OR maximum_recharge_minor IS NULL
                OR maximum_recharge_minor >= minimum_recharge_minor
            )
        SQL);

        foreach (self::SRS_MINIMUMS as $code => $minimumMinor) {
            DB::table('currencies')
                ->where('code', $code)
                ->update(['minimum_recharge_minor' => $minimumMinor]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE currencies DROP CONSTRAINT chk_currencies_recharge_limit_order');

        Schema::table('currencies', function (Blueprint $table): void {
            $table->dropColumn(['minimum_recharge_minor', 'maximum_recharge_minor']);
        });
    }
};
