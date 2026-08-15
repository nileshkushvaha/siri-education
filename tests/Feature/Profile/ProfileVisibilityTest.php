<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_their_profile_visibility(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user)
            ->post(route('profile.visibility.update'), [
                'profile_visibility' => 'private',
                'show_email' => true,
                'show_phone' => true,
                'show_social_links' => false,
            ])
            ->assertRedirect();

        $profile = $user->profile->fresh();
        $this->assertSame('private', $profile->profile_visibility);
        $this->assertTrue($profile->show_email);
        $this->assertTrue($profile->show_phone);
        $this->assertFalse($profile->show_social_links);
    }

    public function test_visibility_update_is_rejected_for_invalid_value(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user)
            ->post(route('profile.visibility.update'), ['profile_visibility' => 'not-a-real-option'])
            ->assertSessionHasErrors('profile_visibility');
    }

    public function test_visibility_update_logs_activity(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user)->post(route('profile.visibility.update'), [
            'profile_visibility' => 'members_only',
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'profile',
            'event' => 'visibility_changed',
            'causer_id' => $user->id,
        ]);
    }

    public function test_profile_update_logs_activity(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user)->post(route('profile.update'), [
            'first_name' => 'Updated',
        ]);

        $this->assertTrue(
            Activity::where('log_name', 'profile')->where('causer_id', $user->id)->exists()
        );
    }

    private function activeUser(array $overrides = []): User
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $user = User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
        $user->assignRole('instructor');

        return $user;
    }
}
