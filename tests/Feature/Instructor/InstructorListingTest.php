<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    private function makeInstructor(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge(['status' => 'active'], $overrides));
        $user->profile->update([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
        ]);
        $user->assignRole('instructor');

        return $user;
    }

    public function test_listing_page_loads_for_guest(): void
    {
        $this->get(route('instructors.index'))->assertOk();
    }

    public function test_active_public_instructor_appears_in_listing(): void
    {
        $instructor = $this->makeInstructor(['name' => 'Visible Instructor']);

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertSee('Visible Instructor');
    }

    public function test_inactive_instructor_not_listed(): void
    {
        $user = User::factory()->create(['status' => 'inactive', 'name' => 'Inactive Instructor']);
        $user->profile->update([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
        ]);
        $user->assignRole('instructor');

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertDontSee('Inactive Instructor');
    }

    public function test_private_profile_instructor_not_listed(): void
    {
        $user = User::factory()->create(['status' => 'active', 'name' => 'Private Instructor']);
        $user->profile->update([
            'profile_visibility' => 'private',
            'instructor_status' => InstructorStatus::Approved,
        ]);
        $user->assignRole('instructor');

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertDontSee('Private Instructor');
    }

    public function test_members_only_instructor_not_listed(): void
    {
        $user = User::factory()->create(['status' => 'active', 'name' => 'Members Only Instructor']);
        $user->profile->update([
            'profile_visibility' => 'members_only',
            'instructor_status' => InstructorStatus::Approved,
        ]);
        $user->assignRole('instructor');

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertDontSee('Members Only Instructor');
    }

    public function test_non_instructor_user_not_listed(): void
    {
        $user = User::factory()->create(['status' => 'active', 'name' => 'Student Only']);
        $user->profile->update(['profile_visibility' => 'public']);
        $user->assignRole('student');

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertDontSee('Student Only');
    }

    public function test_pending_instructor_not_listed(): void
    {
        $user = User::factory()->create(['status' => 'active', 'name' => 'Pending Instructor']);
        $user->profile->update([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Submitted,
        ]);
        $user->assignRole('instructor');

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertDontSee('Pending Instructor');
    }

    public function test_keyword_search_filters_by_name(): void
    {
        $this->makeInstructor(['name' => 'Alice Walker']);
        $this->makeInstructor(['name' => 'Bob Builder']);

        $this->get(route('instructors.index', ['q' => 'Alice']))
            ->assertOk()
            ->assertSee('Alice Walker')
            ->assertDontSee('Bob Builder');
    }

    public function test_subject_filter_limits_instructor_listing(): void
    {
        $mathInstructor = $this->makeInstructor(['name' => 'Math Instructor']);
        $englishInstructor = $this->makeInstructor(['name' => 'English Instructor']);

        TeacherSubject::factory()->create(['teacher_id' => $mathInstructor->id, 'subject' => 'maths']);
        TeacherSubject::factory()->create(['teacher_id' => $englishInstructor->id, 'subject' => 'english']);

        $this->get(route('instructors.index', ['subject' => 'maths']))
            ->assertOk()
            ->assertSee('Math Instructor')
            ->assertDontSee('English Instructor');
    }

    public function test_language_filter_limits_instructor_listing(): void
    {
        $englishInstructor = $this->makeInstructor(['name' => 'English Speaking Instructor']);
        $spanishInstructor = $this->makeInstructor(['name' => 'Spanish Speaking Instructor']);

        $englishInstructor->profile->update(['language' => 'English']);
        $spanishInstructor->profile->update(['language' => 'Spanish']);

        $this->get(route('instructors.index', ['language' => 'English']))
            ->assertOk()
            ->assertSee('English Speaking Instructor')
            ->assertDontSee('Spanish Speaking Instructor');
    }

    public function test_availability_filter_limits_instructor_listing(): void
    {
        $availableInstructor = $this->makeInstructor(['name' => 'Available Instructor']);
        $unavailableInstructor = $this->makeInstructor(['name' => 'Unavailable Instructor']);

        TeacherAvailability::factory()
            ->forDay(Weekday::Monday)
            ->between('09:00:00', '11:00:00')
            ->create(['teacher_id' => $availableInstructor->id]);

        TeacherAvailability::factory()
            ->forDay(Weekday::Tuesday)
            ->between('09:00:00', '11:00:00')
            ->create(['teacher_id' => $unavailableInstructor->id, 'is_active' => false]);

        $this->get(route('instructors.index', ['available' => '1']))
            ->assertOk()
            ->assertSee('Available Instructor')
            ->assertDontSee('Unavailable Instructor');
    }

    public function test_featured_instructors_appear_first(): void
    {
        $regular = $this->makeInstructor(['name' => 'Regular Instructor']);
        $featured = $this->makeInstructor(['name' => 'Featured Instructor']);
        $featured->profile->update(['is_featured' => true, 'featured_order' => 1]);

        $response = $this->get(route('instructors.index'));
        $content = $response->content();

        $this->assertLessThan(
            strpos($content, 'Regular Instructor'),
            strpos($content, 'Featured Instructor'),
        );
    }

    public function test_empty_state_shown_when_no_instructors(): void
    {
        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertSee('No instructors found');
    }
}
