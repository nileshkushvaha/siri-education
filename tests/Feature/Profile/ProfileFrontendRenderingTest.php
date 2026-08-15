<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\Country;
use App\Models\Currency;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileFrontendRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_profile_page_renders_the_general_tab_fields(): void
    {
        $user = $this->activeUser();
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $user->assignRole('instructor');
        $user->profile->update(['headline' => 'Senior Instructor', 'bio' => 'Hello world']);

        $content = $this->actingAs($user)->get(route('profile.show'))->getContent();

        $this->assertStringContainsString('Senior Instructor', $content);
        $this->assertStringContainsString('name="headline"', $content);
        $this->assertStringContainsString('name="bio"', $content);
        $this->assertStringContainsString('name="short_bio"', $content);
    }

    public function test_profile_page_renders_social_link_fields(): void
    {
        $user = $this->activeUser();
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $user->assignRole('instructor');

        $content = $this->actingAs($user)->get(route('profile.show'))->getContent();

        foreach (['website', 'facebook', 'twitter', 'linkedin', 'github', 'instagram', 'youtube'] as $field) {
            $this->assertStringContainsString("name=\"{$field}\"", $content);
        }
    }

    public function test_student_and_instructor_profile_fields_are_audience_isolated(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $student = $this->activeUser();
        $student->assignRole('student');
        $instructor = $this->activeUser();
        $instructor->assignRole('instructor');

        $studentContent = $this->actingAs($student)->get(route('profile.show'))->getContent();
        $instructorContent = $this->actingAs($instructor)->get(route('profile.show'))->getContent();

        $this->assertStringContainsString('Learning Profile', $studentContent);
        $this->assertStringNotContainsString('name="headline"', $studentContent);
        $this->assertStringContainsString('Teaching profile setup', $instructorContent);
        $this->assertStringNotContainsString('name="student_academic_level_id"', $instructorContent);
    }

    public function test_profile_update_rejects_fields_from_the_other_audience(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = $this->activeUser();
        $student->assignRole('student');

        $this->actingAs($student)->post(route('profile.update'), [
            'first_name' => $student->first_name,
            'headline' => 'This must not become a public instructor headline',
        ])->assertSessionHasErrors('headline');

        $this->assertNull($student->profile->fresh()->headline);
    }

    public function test_profile_update_normalizes_select_ids_and_returns_validation_errors_for_invalid_values(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = $this->activeUser();
        $student->assignRole('student');
        $currency = Currency::factory()->create(['status' => 'active']);
        $country = Country::factory()->create(['default_currency_id' => $currency->id]);

        $this->actingAs($student)->post(route('profile.update'), [
            'first_name' => 'Student',
            'country_id' => (string) $country->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($country->id, $student->profile->fresh()->country_id);

        $this->actingAs($student)->from(route('profile.show'))->post(route('profile.update'), [
            'first_name' => 'Student',
            'country_id' => 'not-a-country',
        ])->assertRedirect(route('profile.show'))->assertSessionHasErrors('country_id');
    }

    public function test_profile_page_renders_visibility_controls(): void
    {
        $user = $this->activeUser();

        $content = $this->actingAs($user)->get(route('profile.show'))->getContent();

        $this->assertStringContainsString(route('profile.visibility.update'), $content);
        $this->assertStringContainsString('name="profile_visibility"', $content);
    }

    public function test_profile_page_renders_the_completion_component(): void
    {
        $user = $this->activeUser();

        $content = $this->actingAs($user)->get(route('profile.show'))->getContent();

        $this->assertStringContainsString('data-account-profile-completion', $content);
    }

    public function test_profile_page_offers_state_options_scoped_to_country(): void
    {
        $country = Country::factory()->create(['name' => 'Wonderland']);
        $state = State::factory()->create(['country_id' => $country->id, 'name' => 'Looking Glass']);
        $user = $this->activeUser();

        $content = $this->actingAs($user)->get(route('profile.show'))->getContent();

        $this->assertStringContainsString('Looking Glass', $content);
        $this->assertStringContainsString((string) $country->id, $content);
    }

    public function test_a_user_cannot_view_another_users_profile_data(): void
    {
        $userA = $this->activeUser();
        $userB = $this->activeUser();
        $userB->profile->update(['bio' => 'This is user B private bio content']);

        $content = $this->actingAs($userA)->get(route('profile.show'))->getContent();

        $this->assertStringNotContainsString('This is user B private bio content', $content);
    }

    public function test_updating_your_own_profile_does_not_notify_admins(): void
    {
        // Deliberately NOT using Notification::fake() here — we want the
        // real Activity -> NotifyAdminsOnActivity -> NotificationMapper
        // pipeline to run (QUEUE_CONNECTION=sync in testing) and prove it
        // stays silent, not just that a fake intercepted it.
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['status' => 'active']);
        $superAdmin->assignRole('super_admin');
        $user = $this->activeUser();

        $this->actingAs($user)->post(route('profile.update'), ['first_name' => 'Changed']);
        $this->actingAs($user)->post(route('profile.visibility.update'), ['profile_visibility' => 'private']);

        // No notifiable case exists for log_name=profile in NotificationMapper,
        // so no database notification should ever be created for these events.
        $this->assertDatabaseCount('notifications', 0);
    }
}
