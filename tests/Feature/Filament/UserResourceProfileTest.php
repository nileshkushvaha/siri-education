<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\ActivityLogRelationManager;
use App\Filament\Resources\Users\RelationManagers\LoginHistoryRelationManager;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin edits the SAME profile the frontend edits — one source of truth.
 * This is the highest-risk integration point: nested
 * Section::make()->relationship('profile') fields, plus
 * SpatieMediaLibraryFileUpload nested inside that same relationship
 * section, must save to the one UserProfile row without creating a
 * duplicate or violating the unique user_id constraint (UserObserver
 * already auto-creates a profile when the User record is created).
 */
class UserResourceProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('super_admin');
        $this->actingAs($this->superAdmin);
    }

    public function test_creating_a_user_creates_exactly_one_profile_row_with_the_submitted_data(): void
    {
        $country = Country::factory()->create();
        $state = State::factory()->create(['country_id' => $country->id]);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Person',
                'email' => 'new-person@sirieducation.com',
                'password' => 'Sup3r$ecret!',
                'password_confirmation' => 'Sup3r$ecret!',
                'status' => 'active',
                'profile.headline' => 'Senior Instructor',
                'profile.bio' => 'A short bio.',
                'profile.phone' => '5551234',
                'profile.country_id' => $country->id,
                'profile.state_id' => $state->id,
                'profile.city' => 'Metropolis',
                'profile.website' => 'https://sirieducation.com',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'new-person@sirieducation.com')->firstOrFail();

        $this->assertSame(1, $user->profile()->count());
        $this->assertSame('Senior Instructor', $user->profile->headline);
        $this->assertSame('Metropolis', $user->profile->city);
        $this->assertSame($state->id, $user->profile->state_id);
        $this->assertSame($this->superAdmin->id, $user->profile->created_by);
    }

    public function test_editing_a_user_updates_the_same_profile_row_admin_and_frontend_share(): void
    {
        $target = User::factory()->create();
        $originalProfileId = $target->profile->id;

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'name' => $target->name,
                'email' => $target->email,
                'status' => 'active',
                'profile.headline' => 'Updated Headline',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();
        $this->assertSame($originalProfileId, $target->profile->id, 'A new profile row was created instead of updating the existing one.');
        $this->assertSame('Updated Headline', $target->profile->headline);
    }

    public function test_admin_can_upload_avatar_and_cover_through_the_media_tab(): void
    {
        $target = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'name' => $target->name,
                'email' => $target->email,
                'status' => 'active',
                'profile.avatar' => [UploadedFile::fake()->image('avatar.jpg')],
                'profile.cover' => [UploadedFile::fake()->image('cover.jpg')],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $profile = $target->profile->fresh();
        $this->assertTrue($profile->hasMedia('avatar'));
        $this->assertTrue($profile->hasMedia('cover'));
    }

    public function test_activity_log_relation_manager_shows_only_this_users_activity(): void
    {
        $target = User::factory()->create();
        $other = User::factory()->create();

        activity('profile')->causedBy($target)->performedOn($target)->log('Target did something');
        activity('profile')->causedBy($other)->performedOn($other)->log('Other did something');

        Livewire::test(ActivityLogRelationManager::class, [
            'ownerRecord' => $target,
            'pageClass' => EditUser::class,
        ])
            ->assertCanSeeTableRecords($target->activities)
            ->assertCanNotSeeTableRecords($other->activities);
    }

    public function test_login_history_relation_manager_shows_only_this_users_history(): void
    {
        $target = User::factory()->create();
        $other = User::factory()->create();

        $target->loginHistories()->create(['status' => 'success', 'logged_in_at' => now()]);
        $other->loginHistories()->create(['status' => 'success', 'logged_in_at' => now()]);

        Livewire::test(LoginHistoryRelationManager::class, [
            'ownerRecord' => $target,
            'pageClass' => EditUser::class,
        ])
            ->assertCanSeeTableRecords($target->loginHistories)
            ->assertCanNotSeeTableRecords($other->loginHistories);
    }

    public function test_users_table_shows_profile_completion_without_n_plus_one(): void
    {
        User::factory()->count(5)->create();

        $queryCountBefore = 0;
        DB::listen(function () use (&$queryCountBefore): void {
            $queryCountBefore++;
        });

        Livewire::test(ListUsers::class)->assertSuccessful();

        // A handful of queries are expected (auth, permissions, pagination,
        // the eager-loaded profile.media) — this just guards against the
        // count scaling with the number of rows (the N+1 signature).
        $this->assertLessThan(30, $queryCountBefore);
    }
}
