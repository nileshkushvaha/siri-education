<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Email is frozen everywhere on the frontend profile — the update form
 * never accepts it, regardless of what a request sends.
 */
class ProfileEmailFrozenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/settings']);
    }

    public function test_profile_update_ignores_a_different_email_in_the_request(): void
    {
        $user = $this->activeUser(['email' => 'original@example.com']);

        $this->actingAs($user)
            ->post(route('profile.update'), [
                'first_name' => $user->first_name ?? 'First',
                'email' => 'changed@example.com',
            ])
            ->assertRedirect();

        $this->assertSame('original@example.com', $user->fresh()->email);
    }

    public function test_profile_update_succeeds_without_an_email_field_at_all(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user)
            ->post(route('profile.update'), [
                'first_name' => 'Updated',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Updated', $user->fresh()->first_name);
    }

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ], $overrides));
    }
}
