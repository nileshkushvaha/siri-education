<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Events\BookingConfirmed;
use App\Booking\Events\BookingRequested;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\FreeDemoAlreadyUsedException;
use App\Booking\Exceptions\FreeDemoUnavailableException;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\AcademicCategory;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Instructor\InstructorService;
use App\Settings\FeatureSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS-20-5: the platform-wide FeatureSettings::demo_lessons_enabled
 * toggle must be authoritative for
 * NEW free-demo bookings, without affecting the lifecycle of bookings
 * that already exist, and without being confused with (or synchronized
 * with) BookingType::is_active.
 */
class DemoLessonsFeatureToggleTest extends TestCase
{
    use RefreshDatabase;

    private BookingType $demoType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->demoType = BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    private function disableDemos(): void
    {
        $settings = app(FeatureSettings::class);
        $settings->demo_lessons_enabled = false;
        $settings->save();
    }

    private function enableDemos(): void
    {
        $settings = app(FeatureSettings::class);
        $settings->demo_lessons_enabled = true;
        $settings->save();
    }

    private function makeTeacher(string $subject = 'maths'): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], [
            'instructor_status' => 'approved',
            'profile_visibility' => 'public',
            'offers_demo' => true,
        ]);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject($subject, 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $teacher->id])
                ->forDay($day)
                ->between('09:00:00', '17:00:00')
                ->create();
        }

        return $teacher;
    }

    /** An Active student_status is required for booking eligibility — bare role assignment leaves student_status null, which is always denied. */
    private function makeStudent(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function slot(int $daysAhead): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDays($daysAhead)->setTime(10, 0);
    }

    private function demoData(User $student, User $instructor, int $daysAhead): CreateBookingData
    {
        return new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $student->id,
            instructorId: $instructor->id,
            startsAt: $this->slot($daysAhead),
            durationMinutes: 30,
        );
    }

    // ── 1 & 13. Enabled by (documented) default — no explicit save needed ──

    public function test_free_demo_can_be_created_when_the_setting_is_enabled_by_default(): void
    {
        $this->assertTrue(app(FeatureSettings::class)->demo_lessons_enabled, 'demo_lessons_enabled must default to true per its settings migration.');

        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        $booking = app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => BookingStatus::Confirmed->value]);
    }

    // ── 2 & 3. Disabled → rejected, via direct service invocation ──────────

    public function test_free_demo_cannot_be_created_when_the_setting_is_disabled(): void
    {
        $this->disableDemos();

        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        $this->expectException(FreeDemoUnavailableException::class);
        $this->expectExceptionMessage('Free demo lessons are currently unavailable. You can still book a paid lesson.');

        // Direct service call — no Livewire/HTTP layer involved.
        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));
    }

    // ── 4 & 5. No row and no side effects after rejection ───────────────────

    public function test_no_booking_row_or_side_effects_are_created_after_rejection(): void
    {
        Event::fake([BookingRequested::class, BookingConfirmed::class]);
        $this->disableDemos();

        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        try {
            app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));
            $this->fail('Expected FreeDemoUnavailableException.');
        } catch (FreeDemoUnavailableException) {
            // expected
        }

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_meetings', 0);
        Event::assertNotDispatched(BookingRequested::class);
        Event::assertNotDispatched(BookingConfirmed::class);
    }

    // ── 6. Paid booking remains available while demos are disabled ─────────

    public function test_paid_booking_remains_available_while_demos_are_disabled(): void
    {
        $this->disableDemos();

        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        $currency = Currency::factory()->create(['code' => 'USD']);
        $country = Country::factory()->create(['default_currency_id' => $currency->id]);
        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Maths', 'slug' => 'maths']);
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $country->id]);

        $paidType = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60]);
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $paidType->id,
            'subject_id' => $subject->id,
            'academic_level_id' => null,
            'country_id' => $country->id,
            'currency_id' => $currency->id,
            'currency_code' => $currency->code,
            'duration_minutes' => 60,
            'amount_minor' => 4999,
        ]);

        $paidBooking = app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $student->id,
            instructorId: $teacher->id,
            startsAt: $this->slot(5),
            durationMinutes: 60,
            meta: ['subject' => 'maths', 'grade' => 7],
        ));

        $this->assertDatabaseHas('bookings', ['id' => $paidBooking->id, 'booking_type_id' => $paidType->id]);
    }

    // ── 7. Existing demo bookings remain visible/operational after disabling ─

    public function test_existing_free_demo_bookings_remain_visible_and_operational_after_disabling(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        $existing = app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));

        $this->disableDemos();

        $repository = app(BookingRepositoryInterface::class);

        // Still visible in the student's history.
        $this->assertTrue($repository->paginatedForUser($student->id)->getCollection()->contains('id', $existing->id));

        // Still cancellable through the normal lifecycle action.
        $cancelled = app(BookingServiceInterface::class)->cancel($existing, new CancelBookingData(
            BookingActor::Student,
            'No longer needed.',
        ));

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
    }

    // ── 8. Stale Livewire wizard submission rejected after admin disables ──

    public function test_stale_wizard_submission_is_rejected_after_admin_disables_demos_mid_session(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $student->assignRole('student');

        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);

        $start = $this->slot(3)->toIso8601String();

        $component = Livewire::actingAs($student)
            ->withQueryParams(['instructor' => $teacher->slug, 'type' => 'free_demo', 'subject' => 'maths'])
            ->test('frontend.booking.booking-wizard')
            ->assertSet('type', 'free_demo')
            ->call('selectGrade', 5)
            ->call('selectDate', $this->slot(3)->toDateString())
            ->call('selectSlot', $start);

        // Admin disables demos while the form is still open on "review".
        $this->disableDemos();

        $component
            ->call('submit')
            ->assertSet('step', 6)
            ->assertSet('banner', 'Free demo lessons are currently unavailable. You can still book a paid lesson.');

        $this->assertDatabaseCount('bookings', 0);
    }

    // ── 9. Demo actions unavailable on public/student entry points ─────────

    public function test_demo_mode_is_absent_from_the_wizard_type_list_when_disabled(): void
    {
        $this->disableDemos();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = $this->makeStudent();
        $student->assignRole('student');

        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->assertSet('types', fn (array $types): bool => ! collect($types)->pluck('key')->contains('free_demo'));
    }

    public function test_a_stale_free_demo_query_param_does_not_preselect_the_mode_when_disabled(): void
    {
        $this->disableDemos();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = $this->makeStudent();
        $student->assignRole('student');

        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);

        Livewire::actingAs($student)
            ->withQueryParams(['type' => 'free_demo'])
            ->test('frontend.booking.booking-wizard')
            ->assertSet('type', null)
            ->assertSet('step', 1);
    }

    public function test_public_instructor_profile_hides_the_demo_cta_when_disabled(): void
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $teacher = $this->makeTeacher();
        $teacher->assignRole('instructor');

        $request = Request::create('/instructors/'.$teacher->slug);
        $request->setLaravelSession($this->app['session']->driver());

        $enabledProfile = app(InstructorService::class)->publicProfile($teacher, $request);
        $this->assertTrue($enabledProfile['offersDemo']);

        $this->disableDemos();

        $disabledProfile = app(InstructorService::class)->publicProfile($teacher, $request);
        $this->assertFalse($disabledProfile['offersDemo']);
    }

    // ── 10. Re-enabling restores demo creation without deployment ──────────

    public function test_re_enabling_the_setting_restores_demo_creation(): void
    {
        $this->disableDemos();

        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        try {
            app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));
            $this->fail('Expected FreeDemoUnavailableException while disabled.');
        } catch (FreeDemoUnavailableException) {
            // expected
        }

        $this->enableDemos();

        $booking = app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => BookingStatus::Confirmed->value]);
    }

    // ── 11. One-demo-per-instructor rule still applies while enabled ───────

    public function test_one_demo_per_instructor_rule_still_applies_while_demos_are_enabled(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));

        $this->expectException(FreeDemoAlreadyUsedException::class);
        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 7));
    }

    // ── 12. Disabled response never reveals prior demo-eligibility history ──

    public function test_disabled_response_does_not_reveal_whether_the_student_already_used_a_demo(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        // Student already consumed their demo with this instructor.
        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));

        $this->disableDemos();

        // Must surface the generic feature-unavailable exception, never
        // FreeDemoAlreadyUsedException — disabling must not leak eligibility
        // history through which exception type/message is thrown.
        $this->expectException(FreeDemoUnavailableException::class);
        $this->expectExceptionMessage('Free demo lessons are currently unavailable. You can still book a paid lesson.');

        app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 7));
    }

    // ── 14. Booking-type activation and the global toggle combine, unsynced ─

    public function test_booking_type_deactivation_and_the_global_toggle_are_independent(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        // (a) Type active + global enabled → allowed (baseline, already
        // covered above). (b) Type inactive + global enabled → rejected by
        // the type-lookup itself (BookingTypeRepository::requireActiveByKey),
        // a distinct generic BookingException — never ours, and never
        // FreeDemoUnavailableException/FreeDemoAlreadyUsedException — proving
        // the two mechanisms are genuinely separate code paths.
        $this->demoType->update(['is_active' => false]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Booking type "free_demo" is not currently accepting bookings.');

        try {
            app(BookingServiceInterface::class)->request($this->demoData($student, $teacher, 3));
        } finally {
            // Disabling the type must never have touched the global setting.
            $this->assertTrue(app(FeatureSettings::class)->demo_lessons_enabled);
        }
    }

    public function test_disabling_the_global_toggle_never_touches_booking_type_active_status(): void
    {
        $this->disableDemos();

        $this->demoType->refresh();
        $this->assertTrue($this->demoType->is_active, 'Disabling the global feature toggle must never write to booking_types.is_active.');
    }
}
