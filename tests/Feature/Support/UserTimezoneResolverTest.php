<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Country;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\Timezone\IanaTimezone;
use App\Support\UserTimezoneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TZ-1: the canonical resolution chain, tier by tier, including every
 * degradation path.
 *
 *     valid profile timezone
 *         -> Country.default_timezone
 *             -> GeneralSettings.default_timezone
 *                 -> UTC
 *
 * The degradation tests matter as much as the happy path: before TZ-1,
 * a malformed stored timezone was passed straight into `->timezone()`
 * and threw, taking down dashboards and queued notification jobs.
 */
class UserTimezoneResolverTest extends TestCase
{
    use RefreshDatabase;

    private function platformDefault(string $timezone): void
    {
        $settings = app(GeneralSettings::class);
        $settings->default_timezone = $timezone;
        $settings->save();
    }

    private function userWith(?string $profileTimezone, ?string $countryTimezone = null): User
    {
        $user = User::factory()->create();

        $country = $countryTimezone === null
            ? null
            : Country::factory()->create(['default_timezone' => $countryTimezone]);

        $user->profile()->update([
            'timezone' => $profileTimezone,
            'country_id' => $country?->id,
        ]);

        return $user->fresh();
    }

    // ── A. Resolution order ─────────────────────────────────────────────

    public function test_explicit_profile_timezone_wins(): void
    {
        $this->platformDefault('Asia/Kolkata');

        $user = $this->userWith('Europe/London', 'America/New_York');

        $this->assertSame('Europe/London', UserTimezoneResolver::resolve($user));
    }

    public function test_country_default_is_used_when_profile_has_none(): void
    {
        $this->platformDefault('Asia/Kolkata');

        $user = $this->userWith(null, 'America/New_York');

        $this->assertSame('America/New_York', UserTimezoneResolver::resolve($user));
    }

    public function test_platform_default_is_used_when_profile_and_country_have_none(): void
    {
        $this->platformDefault('Europe/Berlin');

        $user = $this->userWith(null, null);

        $this->assertSame('Europe/Berlin', UserTimezoneResolver::resolve($user));
    }

    public function test_utc_is_the_final_fallback(): void
    {
        $this->platformDefault('Not/AZone');

        $user = $this->userWith(null, null);

        $this->assertSame('UTC', UserTimezoneResolver::resolve($user));
        $this->assertSame('UTC', UserTimezoneResolver::PLATFORM_FALLBACK);
    }

    public function test_country_with_a_blank_default_falls_through_rather_than_resolving_to_empty(): void
    {
        $this->platformDefault('Europe/Berlin');

        $user = $this->userWith(null, null);
        $user->profile->country()->associate(Country::factory()->create(['default_timezone' => '']))->save();

        $this->assertSame('Europe/Berlin', UserTimezoneResolver::resolve($user->fresh()));
    }

    // ── Degradation: every tier is validated independently ──────────────

    public function test_invalid_profile_timezone_degrades_to_country(): void
    {
        $this->platformDefault('Asia/Kolkata');

        $user = $this->userWith('Invalid/Timezone', 'America/New_York');

        $this->assertSame('America/New_York', UserTimezoneResolver::resolve($user));
    }

    public function test_invalid_profile_and_country_degrade_to_platform_default(): void
    {
        $this->platformDefault('Europe/Berlin');

        $user = $this->userWith('Invalid/Timezone', 'Also/Invalid');

        $this->assertSame('Europe/Berlin', UserTimezoneResolver::resolve($user));
    }

    public function test_every_tier_invalid_degrades_to_utc_without_throwing(): void
    {
        $this->platformDefault('Nope/Nope');

        $user = $this->userWith('Invalid/Timezone', 'Also/Invalid');

        $this->assertSame('UTC', UserTimezoneResolver::resolve($user));
    }

    public function test_legacy_non_canonical_stored_values_degrade_rather_than_being_trusted(): void
    {
        // All four parse fine through `new DateTimeZone(...)`, which is
        // precisely why constructor success is not the validity test.
        // None can model a DST rule, so none may survive as a profile
        // timezone.
        $this->platformDefault('Europe/Berlin');

        foreach (['EST', '+05:30', 'US/Eastern', 'Asia/Calcutta'] as $legacy) {
            $user = $this->userWith($legacy, 'America/New_York');

            $this->assertSame(
                'America/New_York',
                UserTimezoneResolver::resolve($user),
                "Legacy value {$legacy} must not be treated as authoritative",
            );
        }
    }

    public function test_a_user_with_no_profile_row_at_all_still_resolves(): void
    {
        $this->platformDefault('Europe/Berlin');

        $user = User::factory()->create();
        $user->profile()->delete();

        $this->assertSame('Europe/Berlin', UserTimezoneResolver::resolve($user->fresh()));
    }

    // ── Return-value invariant ──────────────────────────────────────────

    public function test_resolver_can_never_emit_an_invalid_identifier(): void
    {
        $this->platformDefault('Garbage/Zone');

        $user = $this->userWith('Invalid/Timezone', 'Also/Invalid');
        $resolved = UserTimezoneResolver::resolve($user);

        $this->assertTrue(IanaTimezone::isValid($resolved));

        // The whole point of the invariant: the result is always safe to
        // hand to Carbon, which is what the old inline pattern was not.
        $this->assertSame('UTC', now()->timezone($resolved)->getTimezone()->getName());
    }

    public function test_zone_helper_returns_the_same_resolution_as_a_datetimezone(): void
    {
        $user = $this->userWith('Australia/Sydney');

        $this->assertSame('Australia/Sydney', UserTimezoneResolver::resolveZone($user)->getName());
        $this->assertSame(
            UserTimezoneResolver::resolve($user),
            UserTimezoneResolver::resolveZone($user)->getName(),
        );
    }

    // ── B. Multi-timezone countries ─────────────────────────────────────

    public function test_us_user_without_an_explicit_choice_gets_the_configured_country_default(): void
    {
        $user = $this->userWith(null, 'America/New_York');

        $this->assertSame('America/New_York', UserTimezoneResolver::resolve($user));
    }

    public function test_us_user_on_the_west_coast_overrides_the_country_default(): void
    {
        // THE multi-timezone-country guarantee. The United States spans
        // several zones, so its Country default is a starting point, not
        // an identity — a user in Los Angeles must never be dragged onto
        // New York time.
        $user = $this->userWith('America/Los_Angeles', 'America/New_York');

        $this->assertSame('America/Los_Angeles', UserTimezoneResolver::resolve($user));
    }

    public function test_explicit_choice_beats_the_country_default_for_every_multi_zone_country(): void
    {
        $cases = [
            ['America/Denver', 'America/New_York'],
            ['America/Vancouver', 'America/Toronto'],
            ['Australia/Perth', 'Australia/Sydney'],
            ['Asia/Kolkata', 'Europe/London'],
        ];

        foreach ($cases as [$chosen, $countryDefault]) {
            $user = $this->userWith($chosen, $countryDefault);

            $this->assertSame($chosen, UserTimezoneResolver::resolve($user));
        }
    }

    public function test_country_default_never_overwrites_the_stored_profile_value(): void
    {
        // Resolution is a READ. Resolving through the Country tier must
        // not quietly persist that fallback as the user's explicit
        // choice — otherwise the next country change would be locked out
        // by a value the user never picked.
        $user = $this->userWith(null, 'America/New_York');

        UserTimezoneResolver::resolve($user);

        $this->assertNull($user->fresh()->profile->timezone);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id, 'timezone' => null]);
    }

    // ── Platform default helper ─────────────────────────────────────────

    public function test_platform_default_validates_the_configured_value(): void
    {
        $this->platformDefault('Europe/Lisbon');
        $this->assertSame('Europe/Lisbon', UserTimezoneResolver::platformDefault());

        $this->platformDefault('Rubbish/Value');
        $this->assertSame('UTC', UserTimezoneResolver::platformDefault());
    }
}
