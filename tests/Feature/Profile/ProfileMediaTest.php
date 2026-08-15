<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function activeUser(): User
    {
        return User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_user_can_upload_an_avatar(): void
    {
        $user = $this->activeUser();

        $response = $this->actingAs($user)
            ->post(route('profile.avatar.upload'), [
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
            ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertTrue($user->profile->fresh()->hasMedia('avatar'));
    }

    public function test_student_and_instructor_can_upload_avatar_and_cover_images(): void
    {
        foreach (['student', 'instructor'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $user = $this->activeUser();
            $user->assignRole($roleName);

            $this->actingAs($user)
                ->withHeader('Accept', 'application/json')
                ->post(route('profile.avatar.upload'), [
                    'avatar' => UploadedFile::fake()->image("{$roleName}-avatar.jpg"),
                ])
                ->assertOk()
                ->assertJson(['success' => true]);

            $this->actingAs($user)
                ->withHeader('Accept', 'application/json')
                ->post(route('profile.cover.upload'), [
                    'cover' => UploadedFile::fake()->image("{$roleName}-cover.jpg"),
                ])
                ->assertOk()
                ->assertJson(['success' => true]);

            $profile = $user->profile->fresh();
            $this->assertTrue($profile->hasMedia('avatar'));
            $this->assertTrue($profile->hasMedia('cover'));
        }
    }

    public function test_uploading_a_new_avatar_replaces_the_old_one(): void
    {
        $user = $this->activeUser();
        $this->actingAs($user)->post(route('profile.avatar.upload'), [
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ]);
        $firstMediaId = $user->profile->fresh()->getFirstMedia('avatar')->id;

        $this->actingAs($user)->post(route('profile.avatar.upload'), [
            'avatar' => UploadedFile::fake()->image('second.jpg'),
        ]);

        $profile = $user->profile->fresh();
        $this->assertCount(1, $profile->getMedia('avatar'));
        $this->assertNotSame($firstMediaId, $profile->getFirstMedia('avatar')->id);
    }

    public function test_user_can_delete_their_avatar(): void
    {
        $user = $this->activeUser();
        $this->actingAs($user)->post(route('profile.avatar.upload'), [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $this->actingAs($user)->delete(route('profile.avatar.delete'))
            ->assertOk()->assertJson(['success' => true]);

        $this->assertFalse($user->profile->fresh()->hasMedia('avatar'));
    }

    public function test_user_can_upload_a_cover_photo(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user)
            ->post(route('profile.cover.upload'), [
                'cover' => UploadedFile::fake()->image('cover.jpg'),
            ])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertTrue($user->profile->fresh()->hasMedia('cover'));
    }

    public function test_user_can_delete_their_cover_photo(): void
    {
        $user = $this->activeUser();
        $this->actingAs($user)->post(route('profile.cover.upload'), [
            'cover' => UploadedFile::fake()->image('cover.jpg'),
        ]);

        $this->actingAs($user)->delete(route('profile.cover.delete'))
            ->assertOk()->assertJson(['success' => true]);

        $this->assertFalse($user->profile->fresh()->hasMedia('cover'));
    }

    public function test_avatar_and_cover_are_independent_collections(): void
    {
        $user = $this->activeUser();
        $this->actingAs($user)->post(route('profile.avatar.upload'), [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);
        $this->actingAs($user)->post(route('profile.cover.upload'), [
            'cover' => UploadedFile::fake()->image('cover.jpg'),
        ]);

        $profile = $user->profile->fresh();
        $this->assertTrue($profile->hasMedia('avatar'));
        $this->assertTrue($profile->hasMedia('cover'));
        $this->assertNotSame(
            $profile->getFirstMedia('avatar')->id,
            $profile->getFirstMedia('cover')->id,
        );
    }

    public function test_avatar_upload_logs_activity_and_recalculates_completion(): void
    {
        $user = $this->activeUser();
        $before = $user->profile->profile_completion;

        $this->actingAs($user)->post(route('profile.avatar.upload'), [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'profile',
            'event' => 'avatar_changed',
            'causer_id' => $user->id,
        ]);
        $this->assertGreaterThan($before, $user->profile->fresh()->profile_completion);
    }

    public function test_avatar_upload_rejects_non_image_files(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user)
            ->post(route('profile.avatar.upload'), [
                'avatar' => UploadedFile::fake()->create('document.pdf', 100),
            ])
            ->assertSessionHasErrors('avatar');
    }

    public function test_json_upload_validation_returns_a_field_error(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user)
            ->withHeader('Accept', 'application/json')
            ->post(route('profile.avatar.upload'), [
                'avatar' => UploadedFile::fake()->create('document.pdf', 100),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    }
}
