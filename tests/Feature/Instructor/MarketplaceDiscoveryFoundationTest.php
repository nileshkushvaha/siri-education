<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\Country;
use App\Models\HomeworkAssignment;
use App\Models\Language;
use App\Models\StudentFavoriteInstructor;
use App\Models\Subject;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketplaceDiscoveryFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Subject $algebra;

    private Subject $biology;

    private AcademicLevel $highSchool;

    private AcademicLevel $primary;

    private Language $english;

    private Language $spanish;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $category = AcademicCategory::create(['name' => 'STEM', 'slug' => 'stem']);
        $this->algebra = Subject::create(['academic_category_id' => $category->id, 'name' => 'Algebra', 'slug' => 'algebra']);
        $this->biology = Subject::create(['academic_category_id' => $category->id, 'name' => 'Biology', 'slug' => 'biology']);
        $this->highSchool = AcademicLevel::create(['name' => 'High School', 'slug' => 'high-school', 'min_grade' => 9, 'max_grade' => 12]);
        $this->primary = AcademicLevel::create(['name' => 'Primary', 'slug' => 'primary', 'min_grade' => 1, 'max_grade' => 5]);
        $this->english = Language::create(['name' => 'English', 'code' => 'en', 'status' => 'active']);
        $this->spanish = Language::create(['name' => 'Spanish', 'code' => 'es', 'status' => 'active']);
    }

    public function test_listing_shows_only_active_public_bookable_instructors(): void
    {
        $visible = $this->makeInstructor('Visible Instructor', InstructorStatus::Approved);

        foreach ([InstructorStatus::Draft, InstructorStatus::Submitted, InstructorStatus::UnderReview, InstructorStatus::Rejected, InstructorStatus::Suspended, InstructorStatus::Archived, InstructorStatus::Vacation] as $status) {
            $this->makeInstructor($status->label().' Instructor', $status);
        }

        $this->makeInstructor('Inactive Instructor', InstructorStatus::Approved, ['status' => User::STATUS_INACTIVE]);
        $this->makeInstructor('Private Instructor', InstructorStatus::Approved, [], ['profile_visibility' => 'private']);

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertSee($visible->name)
            ->assertDontSee('Inactive Instructor')
            ->assertDontSee('Private Instructor')
            ->assertDontSee('Suspended Instructor')
            ->assertDontSee('On Vacation Instructor')
            ->assertDontSee('Rejected Instructor');
    }

    public function test_listing_filters_by_subject_master_data_and_legacy_fallback(): void
    {
        $master = $this->makeInstructor('Master Algebra Instructor', InstructorStatus::Approved);
        TeacherSubject::create(['teacher_id' => $master->id, 'subject_id' => $this->algebra->id, 'subject' => $this->algebra->name]);

        $legacy = $this->makeInstructor('Legacy Geometry Instructor', InstructorStatus::Approved);
        TeacherSubject::create(['teacher_id' => $legacy->id, 'subject' => 'legacy_geometry']);

        $biology = $this->makeInstructor('Biology Instructor', InstructorStatus::Approved);
        TeacherSubject::create(['teacher_id' => $biology->id, 'subject_id' => $this->biology->id, 'subject' => $this->biology->name]);

        $this->get(route('instructors.index', ['subject' => $this->algebra->id]))
            ->assertOk()
            ->assertSee('Master Algebra Instructor')
            ->assertDontSee('Biology Instructor');

        $this->get(route('instructors.index', ['subject' => 'legacy_geometry']))
            ->assertOk()
            ->assertSee('Legacy Geometry Instructor')
            ->assertDontSee('Master Algebra Instructor');
    }

    public function test_listing_filters_by_academic_level_language_country_timezone_and_safe_keyword(): void
    {
        $country = Country::create(['name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA', 'status' => 'active']);
        $matching = $this->makeInstructor('Algebra Specialist', InstructorStatus::Approved, [], [
            'headline' => 'Linear equations coach',
            'country_id' => $country->id,
            'timezone' => 'America/New_York',
            'instructor_academic_level_ids' => [$this->highSchool->id],
            'instructor_teaching_language_ids' => [(string) $this->english->id],
        ]);
        TeacherSubject::create(['teacher_id' => $matching->id, 'subject_id' => $this->algebra->id, 'subject' => $this->algebra->name]);

        $other = $this->makeInstructor('Primary Spanish Instructor', InstructorStatus::Approved, [], [
            'timezone' => 'Europe/Madrid',
            'instructor_academic_level_ids' => [$this->primary->id],
            'instructor_teaching_language_ids' => [(string) $this->spanish->id],
        ]);

        $this->get(route('instructors.index', [
            'academic_level' => $this->highSchool->id,
            'language' => (string) $this->english->id,
            'country' => $country->id,
            'timezone' => 'America/New_York',
            'q' => 'equations',
        ]))
            ->assertOk()
            ->assertSee('Algebra Specialist')
            ->assertDontSee($other->name);

        $this->get(route('instructors.index', ['academic_level' => 'not-a-level']))
            ->assertOk()
            ->assertSee('No instructors found');
    }

    public function test_legacy_profile_language_is_not_rendered_or_used_as_marketplace_filter(): void
    {
        $legacyLanguageInstructor = $this->makeInstructor('Legacy Language Instructor', InstructorStatus::Approved, [], [
            'language' => 'en',
            'instructor_teaching_language_ids' => null,
        ]);

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertSee($legacyLanguageInstructor->name)
            ->assertDontSee('en (legacy)');

        $this->get(route('instructors.index', ['language' => 'en']))
            ->assertOk()
            ->assertSee('No instructors found')
            ->assertDontSee($legacyLanguageInstructor->name);
    }

    public function test_country_filter_options_are_scoped_to_visible_bookable_instructors(): void
    {
        $visibleCountry = Country::create(['name' => 'Visible Country', 'iso2' => 'VC', 'iso3' => 'VCO', 'status' => 'active']);
        $rejectedCountry = Country::create(['name' => 'Rejected Country', 'iso2' => 'RC', 'iso3' => 'RCO', 'status' => 'active']);
        $suspendedCountry = Country::create(['name' => 'Suspended Country', 'iso2' => 'SC', 'iso3' => 'SCO', 'status' => 'active']);
        $inactiveCountry = Country::create(['name' => 'Inactive Country', 'iso2' => 'IC', 'iso3' => 'ICO', 'status' => 'active']);
        $privateCountry = Country::create(['name' => 'Private Country', 'iso2' => 'PC', 'iso3' => 'PCO', 'status' => 'active']);

        $visible = $this->makeInstructor('Visible Country Instructor', InstructorStatus::Approved, [], ['country_id' => $visibleCountry->id]);
        $this->makeInstructor('Rejected Country Instructor', InstructorStatus::Rejected, [], ['country_id' => $rejectedCountry->id]);
        $this->makeInstructor('Suspended Country Instructor', InstructorStatus::Suspended, [], ['country_id' => $suspendedCountry->id]);
        $this->makeInstructor('Inactive Country Instructor', InstructorStatus::Approved, ['status' => User::STATUS_INACTIVE], ['country_id' => $inactiveCountry->id]);
        $this->makeInstructor('Private Country Instructor', InstructorStatus::Approved, [], [
            'country_id' => $privateCountry->id,
            'profile_visibility' => 'private',
        ]);

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertSee('Visible Country')
            ->assertSee($visible->name)
            ->assertDontSee('Rejected Country')
            ->assertDontSee('Suspended Country')
            ->assertDontSee('Inactive Country')
            ->assertDontSee('Private Country');

        $this->get(route('instructors.index', ['country' => $rejectedCountry->id]))
            ->assertOk()
            ->assertSee('No instructors found')
            ->assertDontSee('Rejected Country Instructor');
    }

    public function test_profile_visibility_seo_and_private_data_are_safe(): void
    {
        $instructor = $this->makeInstructor('Safe Profile Instructor', InstructorStatus::Approved, [], [
            'bio' => 'Public-safe biography.',
            'short_bio' => 'Public-safe short bio.',
            'instructor_teaching_experience_summary' => 'Public teaching summary.',
            'instructor_teaching_philosophy' => 'Public teaching philosophy.',
            'instructor_review_reason' => 'Private admin review note.',
        ]);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('canonical', false)
            ->assertSee('Public teaching summary')
            ->assertSee('Public teaching philosophy')
            ->assertDontSee('Private admin review note')
            ->assertDontSee('government_id');

        $this->get(route('instructors.show', $this->makeInstructor('Hidden Instructor', InstructorStatus::Rejected)))
            ->assertForbidden();
    }

    public function test_favorite_actions_work_from_listing_and_profile_and_guest_redirects(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        // Interactive student actions (favoriting
        // included) require an Active student_status; a bare role
        // assignment leaves it null and is always denied.
        $student->profile()->update(['student_status' => StudentStatus::Active]);
        $instructor = $this->makeInstructor('Favorite Instructor', InstructorStatus::Approved);

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertSee('Add to favorites', false);

        $this->post(route('dashboard.favorite-instructors.store', $instructor))
            ->assertRedirect(route('auth.login'));

        $this->actingAs($student)
            ->post(route('dashboard.favorite-instructors.store', $instructor))
            ->assertRedirect();

        $this->assertDatabaseCount('student_favorite_instructors', 1);

        $this->actingAs($student)
            ->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('Remove from Favorites');

        $this->actingAs($student)
            ->delete(route('dashboard.favorite-instructors.destroy', $instructor))
            ->assertRedirect();

        $this->assertDatabaseCount('student_favorite_instructors', 0);
    }

    public function test_marketplace_favorite_button_visibility_depends_on_viewer_role(): void
    {
        $instructor = $this->makeInstructor('Favorite Visibility Instructor', InstructorStatus::Approved);
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $instructorViewer = $this->makeInstructor('Viewer Instructor', InstructorStatus::Approved);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertSee('Add to favorites', false);

        $this->actingAs($student)
            ->get(route('instructors.index'))
            ->assertOk()
            ->assertSee('Add to favorites', false);

        $this->actingAs($instructorViewer)
            ->get(route('instructors.index'))
            ->assertOk()
            ->assertDontSee('Add to favorites', false)
            ->assertDontSee('Remove from favorites', false);

        $this->actingAs($admin)
            ->get(route('instructors.index'))
            ->assertOk()
            ->assertDontSee('Add to favorites', false)
            ->assertDontSee('Remove from favorites', false);

        $this->actingAs($instructorViewer)
            ->post(route('dashboard.favorite-instructors.store', $instructor))
            ->assertSessionHasErrors('student');

        $this->actingAs($admin)
            ->post(route('dashboard.favorite-instructors.store', $instructor))
            ->assertRedirect('/admin');

        $this->assertDatabaseCount('student_favorite_instructors', 0);
    }

    public function test_favorites_reject_self_non_bookable_and_duplicates(): void
    {
        $studentInstructor = $this->makeInstructor('Student Instructor', InstructorStatus::Approved);
        $studentInstructor->assignRole('student');
        // See above — required for the duplicate-
        // favorite assertion below to ever reach the actual create path.
        $studentInstructor->profile()->update(['student_status' => StudentStatus::Active]);
        $nonBookable = $this->makeInstructor('Non Bookable Instructor', InstructorStatus::Suspended);

        $this->actingAs($studentInstructor)
            ->post(route('dashboard.favorite-instructors.store', $studentInstructor))
            ->assertSessionHasErrors('instructor');

        $this->actingAs($studentInstructor)
            ->post(route('dashboard.favorite-instructors.store', $nonBookable))
            ->assertSessionHasErrors('instructor');

        $bookable = $this->makeInstructor('Duplicate Favorite Instructor', InstructorStatus::Approved);
        $this->actingAs($studentInstructor)->post(route('dashboard.favorite-instructors.store', $bookable));
        $this->actingAs($studentInstructor)->post(route('dashboard.favorite-instructors.store', $bookable));

        $this->assertSame(1, StudentFavoriteInstructor::query()
            ->where('student_user_id', $studentInstructor->id)
            ->where('instructor_user_id', $bookable->id)
            ->count());
    }

    public function test_marketplace_discovery_does_not_create_out_of_scope_records_or_duplicate_tables(): void
    {
        $instructor = $this->makeInstructor('Boundary Instructor', InstructorStatus::Approved);

        $this->get(route('instructors.index'))->assertOk();
        $this->get(route('instructors.show', $instructor))->assertOk();

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, HomeworkAssignment::count());
        $this->assertFalse(Schema::hasTable('students'));
        $this->assertFalse(Schema::hasTable('student_profiles'));
        $this->assertFalse(Schema::hasTable('instructors'));
        $this->assertFalse(Schema::hasTable('instructor_profiles'));
        // wallets is a separate foundation; marketplace browsing must
        // still never touch it.
        $this->assertSame(0, Wallet::count());
        // `payments` is the sanctioned generic Payable table (Phase
        // 4B.1); browsing must never write to it.
        $this->assertSame(0, DB::table('payments')->count());
    }

    private function makeInstructor(string $name, InstructorStatus $status, array $userOverrides = [], array $profileOverrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'name' => $name,
            'status' => User::STATUS_ACTIVE,
        ], $userOverrides));
        $user->assignRole('instructor');
        $user->profile->update(array_merge([
            'profile_visibility' => 'public',
            'instructor_status' => $status,
        ], $profileOverrides));

        return $user;
    }
}
