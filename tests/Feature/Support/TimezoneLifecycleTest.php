<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Actions\Auth\RegisterUserAction;
use App\Actions\Profile\UpdateProfileAction;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\UserTimezoneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TZ-1: the full lifecycle of `user_profiles.timezone` — how it is
 * seeded at registration, what preserves it, and what may never
 * overwrite it.
 *
 * The retained registration design is MODEL A: the country's configured
 * default is snapshotted as the account's INITIAL explicit timezone, so
 * a brand-new account has a sensible local clock before it ever opens
 * the profile screen. From that point the value is the user's, and the
 * tests below pin the two rules that make model A safe — a country
 * change never rewrites it, and no India-specific literal is ever
 * invented.
 */
class TimezoneLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function platformDefault(string $timezone): void
    {
        $settings = app(GeneralSettings::class);
        $settings->default_timezone = $timezone;
        $settings->save();
    }

    private function country(?string $timezone): Country
    {
        return Country::factory()->create([
            'default_timezone' => $timezone,
            'default_currency_id' => Currency::query()->first()?->id,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function register(Country $country, array $overrides = []): User
    {
        return app(RegisterUserAction::class)->execute([
            'first_name' => 'Test',
            'last_name' => 'Student',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Password123!',
            'country_id' => $country->id,
            'terms' => true,
            ...$overrides,
        ]);
    }

    // ── D. Registration ─────────────────────────────────────────────────

    public function test_registration_snapshots_the_country_default_as_the_initial_timezone(): void
    {
        $user = $this->register($this->country('America/New_York'));

        $this->assertSame('America/New_York', $user->fresh()->profile->timezone);
    }

    public function test_registration_falls_back_to_the_configured_platform_default_not_a_hardcoded_value(): void
    {
        // The old code hardcoded 'UTC' here, and UpdateProfileAction
        // hardcoded 'Asia/Kolkata'. Both are gone: the fallback is now
        // whatever the platform is configured for, so what registration
        // stores and what the resolver would compute agree.
        $this->platformDefault('Europe/Lisbon');

        $user = $this->register($this->country(null));

        $this->assertSame('Europe/Lisbon', $user->fresh()->profile->timezone);
    }

    public function test_registration_rejects_a_non_canonical_country_default(): void
    {
        $this->platformDefault('Europe/Lisbon');

        // A country row carrying a legacy alias must not seed profiles
        // with a value that cannot model DST.
        $user = $this->register($this->country('US/Eastern'));

        $this->assertSame('Europe/Lisbon', $user->fresh()->profile->timezone);
    }

    public function test_registered_timezone_agrees_with_what_the_resolver_would_compute(): void
    {
        $user = $this->register($this->country('Australia/Sydney'))->fresh();

        $this->assertSame(
            UserTimezoneResolver::resolve($user),
            $user->profile->timezone,
        );
    }

    // ── C. Profile update ───────────────────────────────────────────────

    public function test_an_explicit_valid_timezone_persists(): void
    {
        $user = $this->register($this->country('Asia/Kolkata'));

        app(UpdateProfileAction::class)->execute($user, [
            'first_name' => $user->first_name,
            'timezone' => 'America/Los_Angeles',
        ]);

        $this->assertSame('America/Los_Angeles', $user->fresh()->profile->timezone);
    }

    public function test_changing_country_does_not_overwrite_an_explicit_timezone(): void
    {
        // THE traveling/foreign-number guarantee. Someone living in
        // London with an Indian phone and country on file keeps London.
        $user = $this->register($this->country('Europe/London'));

        app(UpdateProfileAction::class)->execute($user, [
            'first_name' => $user->first_name,
            'timezone' => 'Europe/London',
        ]);

        $india = $this->country('Asia/Kolkata');

        app(UpdateProfileAction::class)->execute($user, [
            'first_name' => $user->first_name,
            'country_id' => $india->id,
        ]);

        $fresh = $user->fresh();

        $this->assertSame($india->id, $fresh->profile->country_id);
        $this->assertSame('Europe/London', $fresh->profile->timezone);
        $this->assertSame('Europe/London', UserTimezoneResolver::resolve($fresh));
    }

    public function test_an_update_that_omits_timezone_preserves_the_stored_value(): void
    {
        $user = $this->register($this->country('Australia/Sydney'));

        app(UpdateProfileAction::class)->execute($user, [
            'first_name' => 'Renamed',
            'city' => 'Perth',
        ]);

        $this->assertSame('Australia/Sydney', $user->fresh()->profile->timezone);
        $this->assertSame('Renamed', $user->fresh()->first_name);
    }

    public function test_a_profile_with_no_stored_timezone_never_receives_an_india_specific_fallback(): void
    {
        $this->platformDefault('Europe/Lisbon');

        $user = $this->register($this->country(null));
        $user->profile()->update(['timezone' => null]);

        app(UpdateProfileAction::class)->execute($user->fresh(), [
            'first_name' => $user->first_name,
        ]);

        $this->assertSame('Europe/Lisbon', $user->fresh()->profile->timezone);
    }

    // ── Request validation ──────────────────────────────────────────────

    public function test_profile_endpoint_accepts_a_canonical_identifier(): void
    {
        $user = $this->register($this->country('Asia/Kolkata'));
        $user->update(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);

        $this->actingAs($user)
            ->from(route('profile.show'))
            ->post(route('profile.update'), [
                'first_name' => $user->first_name,
                'timezone' => 'Europe/London',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Europe/London', $user->fresh()->profile->timezone);
    }

    public function test_profile_endpoint_rejects_ambiguous_and_offset_timezones(): void
    {
        $user = $this->register($this->country('Asia/Kolkata'));
        $user->update(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);

        foreach (['EST', '+05:30', 'GMT+5', 'US/Eastern', 'not-a-timezone'] as $rejected) {
            $this->actingAs($user)
                ->from(route('profile.show'))
                ->post(route('profile.update'), [
                    'first_name' => $user->first_name,
                    'timezone' => $rejected,
                ])
                ->assertSessionHasErrors('timezone');

            $this->assertSame(
                'Asia/Kolkata',
                $user->fresh()->profile->timezone,
                "{$rejected} must not have been persisted",
            );
        }
    }
}
