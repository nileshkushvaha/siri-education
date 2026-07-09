<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\StudentLessonPrices\Pages\CreateStudentLessonPrice;
use App\Filament\Resources\StudentLessonPrices\Pages\EditStudentLessonPrice;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 10.2D — admin CRUD + permission boundary for the student
 * pricing matrix, and the structural guarantee that an instructor can
 * never reach it (Filament panel access itself is gated to the admin
 * portal — see User::canAccessPanel()).
 */
class StudentLessonPriceAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BookingType $paidType;

    private Subject $subject;

    private AcademicLevel $level;

    private Country $country;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('super_admin');

        $this->paidType = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);
        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $this->subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Maths', 'slug' => 'maths']);
        $this->level = AcademicLevel::create(['name' => 'Middle School', 'slug' => 'middle-school', 'min_grade' => 6, 'max_grade' => 8]);
        $this->currency = Currency::factory()->create(['code' => 'INR', 'minor_units' => 2]);
        $this->country = Country::factory()->create(['iso2' => 'IN', 'default_currency_id' => $this->currency->id]);
    }

    // ── 1. admin can create ─────────────────────────────────────────────

    public function test_admin_can_create_student_lesson_price(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateStudentLessonPrice::class)
            ->fillForm([
                'booking_type_id' => $this->paidType->id,
                'subject_id' => $this->subject->id,
                'academic_level_id' => $this->level->id,
                'country_id' => $this->country->id,
                'currency_id' => $this->currency->id,
                'duration_minutes' => 60,
                'amount' => 499,
                'is_active' => true,
                'priority' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('student_lesson_prices', [
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'currency_code' => 'INR',
            'amount_minor' => 49900,
        ]);
    }

    public function test_duplicate_active_price_for_the_same_match_key_is_rejected(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 49900,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(CreateStudentLessonPrice::class)
            ->fillForm([
                'booking_type_id' => $this->paidType->id,
                'subject_id' => $this->subject->id,
                'academic_level_id' => $this->level->id,
                'country_id' => $this->country->id,
                'currency_id' => $this->currency->id,
                'duration_minutes' => 60,
                'amount' => 599,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['duration_minutes']);
    }

    public function test_amount_field_round_trips_correctly_on_edit(): void
    {
        $price = StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 49900,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(EditStudentLessonPrice::class, ['record' => $price->getRouteKey()])
            ->assertFormSet(['amount' => 499])
            ->fillForm(['amount' => 550])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(55000, $price->fresh()->amount_minor);
    }

    // ── 2. non-permitted admin cannot manage price ──────────────────────

    public function test_non_permitted_manager_cannot_view_or_create_prices(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager'); // no StudentLessonPrice permissions granted

        $this->actingAs($manager)
            ->get('/admin/student-lesson-prices')
            ->assertForbidden();

        $this->actingAs($manager)
            ->get('/admin/student-lesson-prices/create')
            ->assertForbidden();
    }

    public function test_manager_with_explicit_permission_can_manage_prices(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach (['ViewAny:StudentLessonPrice', 'Create:StudentLessonPrice'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');
        $manager->givePermissionTo(['ViewAny:StudentLessonPrice', 'Create:StudentLessonPrice']);

        $this->actingAs($manager)
            ->get('/admin/student-lesson-prices')
            ->assertOk();

        $this->actingAs($manager)
            ->get('/admin/student-lesson-prices/create')
            ->assertOk();
    }

    // ── 12. instructor cannot see student price ─────────────────────────

    public function test_instructor_cannot_access_the_pricing_admin_at_all(): void
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        // Even if every StudentLessonPrice permission were mistakenly
        // granted, the instructor role never uses the admin portal
        // (PortalResolver::usesAdminPortal) — canAccessPanel() denies
        // before any resource-level policy runs.
        foreach (['ViewAny:StudentLessonPrice', 'View:StudentLessonPrice', 'Create:StudentLessonPrice', 'Update:StudentLessonPrice'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->givePermissionTo(['ViewAny:StudentLessonPrice', 'View:StudentLessonPrice', 'Create:StudentLessonPrice', 'Update:StudentLessonPrice']);

        $this->actingAs($instructor)
            ->get('/admin/student-lesson-prices')
            ->assertForbidden();

        $this->actingAs($instructor)
            ->get('/admin')
            ->assertForbidden();
    }

    // ── 13. admin can see price ──────────────────────────────────────────

    public function test_admin_can_see_price_in_the_pricing_table(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 49900,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/student-lesson-prices')
            ->assertOk()
            ->assertSee('499.00');
    }

    // ── Phase 10.2F: instructor-specific price override (admin) ────────

    public function test_admin_can_create_instructor_specific_price(): void
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $instructor->id], ['instructor_status' => 'approved']);

        $this->actingAs($this->admin);

        Livewire::test(CreateStudentLessonPrice::class)
            ->fillForm([
                'booking_type_id' => $this->paidType->id,
                'instructor_id' => $instructor->id,
                'subject_id' => $this->subject->id,
                'academic_level_id' => $this->level->id,
                'country_id' => $this->country->id,
                'currency_id' => $this->currency->id,
                'duration_minutes' => 60,
                'amount' => 1200,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('student_lesson_prices', [
            'booking_type_id' => $this->paidType->id,
            'instructor_id' => $instructor->id,
            'amount_minor' => 120000,
        ]);
    }

    public function test_admin_can_leave_instructor_blank_for_a_base_price(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateStudentLessonPrice::class)
            ->fillForm([
                'booking_type_id' => $this->paidType->id,
                'instructor_id' => null,
                'subject_id' => $this->subject->id,
                'academic_level_id' => $this->level->id,
                'country_id' => $this->country->id,
                'currency_id' => $this->currency->id,
                'duration_minutes' => 60,
                'amount' => 499,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('student_lesson_prices', [
            'booking_type_id' => $this->paidType->id,
            'instructor_id' => null,
            'amount_minor' => 49900,
        ]);
    }

    public function test_instructor_specific_and_base_price_can_coexist_for_the_same_match_key(): void
    {
        // The duplicate-active-row guard is scoped per instructor — a base
        // price and an instructor override for the exact same subject/
        // level/country/duration are not a conflict.
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $instructor->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $instructor->id], ['instructor_status' => 'approved']);

        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 49900,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(CreateStudentLessonPrice::class)
            ->fillForm([
                'booking_type_id' => $this->paidType->id,
                'instructor_id' => $instructor->id,
                'subject_id' => $this->subject->id,
                'academic_level_id' => $this->level->id,
                'country_id' => $this->country->id,
                'currency_id' => $this->currency->id,
                'duration_minutes' => 60,
                'amount' => 1200,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, StudentLessonPrice::query()->count());
    }

    public function test_instructor_cannot_create_edit_or_view_student_lesson_price(): void
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        $price = StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 49900,
        ]);

        $this->assertFalse($instructor->can('viewAny', StudentLessonPrice::class));
        $this->assertFalse($instructor->can('create', StudentLessonPrice::class));
        $this->assertFalse($instructor->can('update', $price));
        $this->assertFalse($instructor->can('view', $price));

        $this->actingAs($instructor)
            ->get('/admin/student-lesson-prices/'.$price->getRouteKey().'/edit')
            ->assertForbidden();
    }

    public function test_no_instructor_facing_dashboard_route_exposes_student_pricing_data(): void
    {
        // No instructor-facing booking/price view exists at all today
        // (confirmed by direct inspection, Phase 10.2D) — this guards
        // against one being added later without the same care this
        // pricing matrix's design otherwise takes.
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $instructor->id], ['instructor_status' => 'approved']);

        StudentLessonPrice::factory()->forInstructor($instructor->id)->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 999900,
        ]);

        foreach (['dashboard.instructor.availability', 'dashboard.instructor.learning-plans'] as $route) {
            $html = $this->actingAs($instructor)->get(route($route))->getContent();
            $this->assertStringNotContainsString('9999.00', (string) $html);
            $this->assertStringNotContainsString('amount_minor', (string) $html);
        }
    }
}
