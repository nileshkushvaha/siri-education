<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserExperience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'Update:User', 'guard_name' => 'web']);
    }

    private function makeInstructor(array $profileOverrides = []): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->profile->update(array_merge([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
            // offers_demo defaults to false; this suite's fixture
            // represents a typical fully-bookable instructor with both CTAs.
            'offers_demo' => true,
        ], $profileOverrides));
        $user->assignRole('instructor');

        return $user;
    }

    public function test_detail_page_loads_for_public_instructor(): void
    {
        $instructor = $this->makeInstructor();

        $this->get(route('instructors.show', $instructor))->assertOk();
    }

    public function test_instructor_name_shown_on_detail_page(): void
    {
        $instructor = $this->makeInstructor();

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee($instructor->name);
    }

    public function test_non_instructor_user_returns_404(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->profile->update(['profile_visibility' => 'public']);

        $this->get(route('instructors.show', $user))->assertNotFound();
    }

    public function test_guest_cannot_view_pending_public_instructor_profile(): void
    {
        $instructor = $this->makeInstructor(['instructor_status' => InstructorStatus::Submitted]);

        $this->get(route('instructors.show', $instructor))->assertForbidden();
    }

    public function test_guest_cannot_view_inactive_approved_instructor_profile(): void
    {
        $instructor = $this->makeInstructor();
        $instructor->update(['status' => User::STATUS_INACTIVE]);

        $this->get(route('instructors.show', $instructor))->assertForbidden();
    }

    public function test_guest_cannot_view_private_instructor_profile(): void
    {
        $instructor = $this->makeInstructor(['profile_visibility' => 'private']);

        $this->get(route('instructors.show', $instructor))->assertForbidden();
    }

    public function test_owner_can_view_their_own_private_profile(): void
    {
        $instructor = $this->makeInstructor(['profile_visibility' => 'private']);

        $this->actingAs($instructor)
            ->get(route('instructors.show', $instructor))
            ->assertOk();
    }

    public function test_admin_with_update_user_can_view_private_profile(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->givePermissionTo('Update:User');
        $instructor = $this->makeInstructor(['profile_visibility' => 'private']);

        $this->actingAs($admin)
            ->get(route('instructors.show', $instructor))
            ->assertOk();
    }

    public function test_guest_cannot_view_members_only_instructor_profile(): void
    {
        $instructor = $this->makeInstructor(['profile_visibility' => 'members_only']);

        $this->get(route('instructors.show', $instructor))->assertForbidden();
    }

    public function test_authenticated_user_can_view_members_only_profile(): void
    {
        $viewer = User::factory()->create(['status' => 'active']);
        $instructor = $this->makeInstructor(['profile_visibility' => 'members_only']);

        $this->actingAs($viewer)
            ->get(route('instructors.show', $instructor))
            ->assertOk();
    }

    public function test_detail_page_has_og_meta_tag(): void
    {
        $instructor = $this->makeInstructor();

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('og:title', false);
    }

    public function test_detail_page_has_json_ld_structured_data(): void
    {
        $instructor = $this->makeInstructor();

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('application/ld+json', false)
            ->assertSee('Person', false);
    }

    public function test_experience_is_shown_on_detail_page(): void
    {
        $instructor = $this->makeInstructor();
        UserExperience::factory()->for($instructor)->create([
            'organization_name' => 'Tech Corp',
            'designation' => 'Senior Dev',
            'is_current' => true,
            'end_date' => null,
        ]);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('Tech Corp');
    }

    public function test_subjects_are_shown_on_detail_page(): void
    {
        $instructor = $this->makeInstructor();
        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => 'physics',
            'grade_from' => 9,
            'grade_to' => 12,
        ]);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('Physics')
            ->assertSee('Grades 9-12');
    }

    public function test_availability_preview_is_shown_on_detail_page(): void
    {
        $instructor = $this->makeInstructor();
        TeacherAvailability::factory()
            ->forDay(Weekday::Monday)
            ->between('09:00:00', '11:00:00')
            ->create(['teacher_id' => $instructor->id]);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('Availability Preview')
            ->assertSee('Monday')
            ->assertSee('09:00 - 11:00');
    }

    public function test_ratings_placeholder_rendered(): void
    {
        $instructor = $this->makeInstructor();

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('Ratings will appear here once course reviews are available.');
    }

    public function test_snapshot_panel_is_rendered(): void
    {
        $instructor = $this->makeInstructor();

        $response = $this->get(route('instructors.show', $instructor));

        $response->assertOk()->assertSee('Instructor Snapshot');
    }

    public function test_booking_links_carry_the_current_instructor_context(): void
    {
        // The demo CTA is gated by DemoAvailabilityResolver (platform
        // toggle AND an active `free_demo` BookingType row) in addition
        // to the instructor's own offers_demo flag — see
        // InstructorService::offersDemo(). Without a real BookingType
        // row here, the demo link is correctly never rendered.
        BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);

        $instructor = $this->makeInstructor();
        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => 'maths',
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $response = $this->get(route('instructors.show', $instructor));

        $response
            ->assertOk()
            ->assertSee('instructor='.$instructor->slug, false)
            ->assertSee('subject=maths', false)
            ->assertSee('type=free_demo', false)
            ->assertSee('type=paid_one_to_one', false);
    }

    public function test_unauthenticated_visitor_following_a_book_button_is_redirected_to_login(): void
    {
        // The profile page itself stays public, but both
        // Book buttons point at booking.create, which now requires auth —
        // this follows that link end-to-end rather than asserting the
        // route in isolation (see BookingWizardLivewireTest for that).
        $instructor = $this->makeInstructor();
        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => 'maths',
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        $this->get(route('instructors.show', $instructor))->assertOk();

        $this->get(route('booking.create', [
            'instructor' => $instructor->slug,
            'subject' => 'maths',
            'type' => 'free_demo',
        ]))->assertRedirect(route('auth.login'));
    }
}
