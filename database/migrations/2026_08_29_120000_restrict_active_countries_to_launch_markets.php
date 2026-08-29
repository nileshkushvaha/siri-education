<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Closes the market gate that
 * docs/payment-collection-and-payout-provider-routing.md §6 assumes and
 * the 2026_11_05 settings migration explicitly claims: "every launch
 * market carries an explicit payment_routing entry" and non-launch
 * countries "are inactive and refused before routing is consulted".
 * Neither was true — all 196 seeded countries were `active` and NOT ONE
 * carried `payment_routing`, so every country on earth fell through to
 * `default_provider` and the only thing stopping an unlaunched market
 * was the incidental absence of a StudentLessonPrice row. A student
 * from an unlaunched country failed at PRICING with "not configured
 * yet" instead of being refused at the market gate.
 *
 * After this, the two definitions of "a market" finally agree:
 * `Country::availableForRegistration()` (active + active default
 * currency) and `PaymentCollectionEligibilityService`'s
 * `status === 'active'` check both resolve to exactly these nine.
 *
 * Data-safe at authoring time — audited before writing: zero
 * instructor_payout_methods rows, zero user_educations/user_experiences
 * rows carrying a country, and all four user_profiles countries inside
 * the launch set.
 *
 * CAVEAT, deliberately recorded rather than silently worked around:
 * `countries.status` is overloaded. Besides the market gate it also
 * backs reference-data surfaces that have nothing to do with where the
 * platform SELLS — most sharply PhoneNumberService::normalize(), which
 * rejects a number whose country is not active. After this migration a
 * person outside these nine cannot save a phone number, record a
 * foreign degree (user_educations), or add a payout bank account in
 * their own country. That is correct for students (who must be in a
 * launch market anyway) but WRONG for instructors, who are routinely
 * resident elsewhere. Splitting "is a market" from "is a known country"
 * is the real fix and is deliberately NOT attempted here — this
 * migration changes data only. See the handoff note in
 * docs/payment-collection-and-payout-provider-routing.md §8.
 */
return new class extends Migration
{
    /**
     * The launch markets — the only countries carrying an active
     * default currency and a StudentLessonPrice matrix.
     *
     * @var list<string>
     */
    private const array LAUNCH_MARKETS = ['IN', 'US', 'GB', 'CA', 'AU', 'AE', 'SG', 'NZ', 'SA'];

    public function up(): void
    {
        DB::table('countries')
            ->whereNotIn('iso2', self::LAUNCH_MARKETS)
            ->update(['status' => 'inactive']);

        // Explicit routing per launch market, rather than leaning on
        // `default_provider` as an implicit catch-all. PaymentProviderResolver
        // consults Country.payment_routing FIRST, so this makes each
        // market's provider a recorded fact that survives someone
        // changing the platform default — and lets a single market be
        // closed with `enabled: false` without touching the others.
        DB::table('countries')
            ->whereIn('iso2', self::LAUNCH_MARKETS)
            ->update([
                'status' => 'active',
                'payment_routing' => json_encode(['provider' => 'razorpay', 'enabled' => true]),
            ]);
    }

    /**
     * Restores the pre-migration state exactly as it was found: every
     * country active, no country routed. This is not a guess — the
     * countries table held 196 rows, all `active`, all with a NULL
     * payment_routing, verified immediately before this was written.
     */
    public function down(): void
    {
        DB::table('countries')->update([
            'status' => 'active',
            'payment_routing' => null,
        ]);
    }
};
