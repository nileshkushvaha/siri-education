<?php

namespace Database\Factories;

use App\Enums\StudentStatus;
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
    public function activeStudent(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->assignRole('student');
            $user->profile()->update(['student_status' => StudentStatus::Active]);
        });
    }
}
