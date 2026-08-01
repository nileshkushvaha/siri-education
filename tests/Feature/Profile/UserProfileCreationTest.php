<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Every user owns exactly one profile from the moment they're created —
 * UserObserver::created() guarantees it going forward, and the
 * backfill_missing_user_profiles migration covers users that predate it.
 */
class UserProfileCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_profile_is_automatically_created_with_a_new_user(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->profile);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id]);
    }

    public function test_the_profile_has_sensible_defaults(): void
    {
        $user = User::factory()->create();

        $this->assertSame('public', $user->profile->profile_visibility);
        $this->assertFalse($user->profile->show_email);
        $this->assertFalse($user->profile->show_phone);
        $this->assertTrue($user->profile->show_social_links);
        $this->assertSame(0, $user->profile->profile_completion);
    }

    public function test_created_by_is_null_for_self_registration(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->profile->created_by);
    }

    public function test_created_by_captures_the_creating_admin(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $newUser = User::factory()->create();

        $this->assertSame($admin->id, $newUser->profile->created_by);
    }

    public function test_a_user_cannot_have_a_second_profile(): void
    {
        $user = User::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        UserProfile::create(['user_id' => $user->id, 'phone' => '555-0100']);
    }

    public function test_deleting_a_user_cascades_to_their_profile(): void
    {
        $user = User::factory()->create();
        $profileId = $user->profile->id;

        $user->delete();

        $this->assertDatabaseMissing('user_profiles', ['id' => $profileId]);
    }

    public function test_backfill_migration_creates_profiles_for_users_missing_one(): void
    {
        // Simulate a pre-Phase-1 user: insert directly via the query builder,
        // bypassing the model/observer entirely.
        $userId = DB::table('users')->insertGetId([
            'name' => 'Legacy User',
            'first_name' => 'Legacy',
            'email' => 'legacy@sirieducation.com',
            'password' => Hash::make('password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseMissing('user_profiles', ['user_id' => $userId]);

        // The migration already ran once during RefreshDatabase's setup, so
        // `php artisan migrate` would treat it as applied and skip it. Load
        // and invoke the migration's up() directly instead.
        $migration = require database_path('migrations/2026_07_02_013938_backfill_missing_user_profiles.php');
        $migration->up();

        $this->assertDatabaseHas('user_profiles', ['user_id' => $userId]);
    }
}
