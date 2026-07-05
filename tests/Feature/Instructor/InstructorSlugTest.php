<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorSlugTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    public function test_slug_is_auto_generated_on_user_creation(): void
    {
        $user = User::factory()->create(['name' => 'Jane Smith']);

        $this->assertNotNull($user->fresh()->slug);
        $this->assertSame('jane-smith', $user->fresh()->slug);
    }

    public function test_slug_is_unique_with_suffix_on_collision(): void
    {
        $first = User::factory()->create(['name' => 'John Doe']);
        $second = User::factory()->create(['name' => 'John Doe']);

        $this->assertSame('john-doe', $first->fresh()->slug);
        $this->assertSame('john-doe_1', $second->fresh()->slug);
    }

    public function test_slug_increments_correctly_for_multiple_collisions(): void
    {
        User::factory()->create(['name' => 'Test User']);
        $second = User::factory()->create(['name' => 'Test User']);
        $third = User::factory()->create(['name' => 'Test User']);

        $this->assertSame('test-user_1', $second->fresh()->slug);
        $this->assertSame('test-user_2', $third->fresh()->slug);
    }

    public function test_slug_route_binding_resolves_correct_user(): void
    {
        $user = User::factory()->create(['name' => 'Route User', 'status' => 'active']);
        $user->profile->update([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
        ]);
        $user->assignRole('instructor');

        $response = $this->get("/instructors/{$user->slug}");

        $response->assertOk();
    }

    public function test_slug_is_regenerated_when_name_changes_and_slug_was_auto(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);
        $this->assertSame('old-name', $user->fresh()->slug);

        $user->update(['name' => 'New Name']);

        $this->assertSame('new-name', $user->fresh()->slug);
    }

    public function test_manual_slug_is_not_overwritten_on_name_change(): void
    {
        $user = User::factory()->create(['name' => 'Some User']);

        // Manually set a custom slug
        $user->update(['slug' => 'my-custom-slug']);
        $this->assertSame('my-custom-slug', $user->fresh()->slug);

        // Change name — since slug doesn't match auto-derived form, should NOT be regenerated
        $user->update(['name' => 'Another Name']);

        $this->assertSame('my-custom-slug', $user->fresh()->slug);
    }
}
