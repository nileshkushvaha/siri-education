<?php

namespace Database\Factories;

use App\Enums\StudentStatus;
use App\Models\Country;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A fully governed, Active student — the
     * 'student' role assigned AND student_status explicitly Active on
     * the profile UserObserver already creates. Use this (not a bare
     * assignRole('student')) whenever a test needs a student who can
     * actually pass StudentLifecycleService::isEligibleForStudentActions()
     * (book, reschedule, cancel, etc.) — a bare role assignment leaves
     * student_status null, which is invalid/ambiguous and always denied
     * under the strict Active-only rule. Still requires the caller to
     * pass ['status' => User::STATUS_ACTIVE] to create()/make() — this
     * state only governs the STUDENT lifecycle column, not the
     * whole-account status, which stays this factory's existing,
     * separate concern.
     */
    /**
     * A student who can BOOK: role, active lifecycle, and the hard booking
     * precondition (StudentProfileCompletenessService) — a supported
     * country, a mobile number and accepted terms. The country is reused
     * from the test's own fixtures when one supported country already
     * exists, so pricing/market resolution stays under the test's control;
     * otherwise a throwaway supported country is created.
     */
    public function activeStudent(): static
    {
        return $this
            ->state(fn (): array => [
                'terms_accepted_at' => now(),
                'privacy_accepted_at' => now(),
            ])
            ->afterCreating(function (User $user): void {
                $user->assignRole('student');

                // UTC keeps fixture times literal: anything that renders in the
                // student's own timezone (the booking wizard, scheduled-time
                // notifications) then matches the UTC instants tests build.
                // Tests that care about a specific zone set it explicitly.
                $user->profile()->update([
                    'student_status' => StudentStatus::Active,
                    'timezone' => 'UTC',
                    'country_id' => $user->profile()->value('country_id') ?? self::supportedCountryId(),
                    'phone' => '+12025550123',
                    'phone_country_iso2' => 'US',
                    'phone_dial_code' => '+1',
                    'phone_national_number' => '2025550123',
                    'phone_e164' => '+12025550123',
                    'phone_verification_status' => 'unverified',
                ]);

                // Tests hand this very instance to actingAs(); drop any
                // profile snapshot loaded before the update above.
                $user->unsetRelation('profile');
            });
    }

    /**
     * The booking gate needs an ACTIVE country on the profile. Reuse one the
     * test already created (so pricing/market context stays under the test's
     * control); otherwise create a plain country with NO currency, so wallet
     * default-currency resolution is never skewed by a fixture.
     */
    private static function supportedCountryId(): int
    {
        $existing = Country::query()->active()->orderBy('id')->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return Country::factory()->create(['default_currency_id' => null])->id;
    }
}
