<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\RecurrenceData;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\RecurrenceFrequency;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Booking-type scope alignment (Version 1 = Free Demo, Paid Lesson
 * single/recurring only) and explicit wizard selection.
 * Structural non-existence of Counselling/Parent-Meeting/Webinar and
 * of the shared-slot mechanism is covered in
 * tests/Architecture/BookingTypeScopeGuardTest.php — this file covers
 * the behavioral scope: explicit selection, CTAs, recurrence, and
 * service-level rejection.
 */
class BookingTypeScopeTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], [
            'instructor_status' => 'approved',
            'profile_visibility' => 'public',
        ]);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        BookingType::query()->firstOrCreate(['key' => 'free_demo'], [
            'name' => 'Free Demo', 'duration_minutes' => 30, 'is_active' => true,
        ]);

        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);
    }

    /** An Active student_status is required for booking eligibility — bare role assignment leaves student_status null, which is always denied. */
    private function student(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function paidTypeWithPrice(): array
    {
        return $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR', durationMinutes: 60);
    }

    // ── 1. No silent default type ─────────────────────────────────────────

    public function test_wizard_has_no_type_selected_without_explicit_input(): void
    {
        Livewire::actingAs($this->student())
            ->test('frontend.booking.booking-wizard')
            ->assertSet('type', null)
            ->assertSet('step', 1);
    }

    public function test_sort_order_or_alphabetical_ordering_cannot_influence_the_default_selection(): void
    {
        // "Counselling" would sort alphabetically before "Free Demo" under
        // the pre-17U.3A ordering bug — but Counselling no longer exists,
        // and even manipulating sort_order/name on the two remaining types
        // must never cause a type to be silently selected.
        BookingType::query()->where('key', 'free_demo')->update(['name' => 'ZZZ Free Demo', 'sort_order' => 99]);

        Livewire::actingAs($this->student())
            ->test('frontend.booking.booking-wizard')
            ->assertSet('type', null);
    }

    public function test_invalid_type_query_param_does_not_select_a_type(): void
    {
        Livewire::actingAs($this->student())
            ->withQueryParams(['type' => 'counselling'])
            ->test('frontend.booking.booking-wizard')
            ->assertSet('type', null)
            ->assertSet('step', 1);
    }

    public function test_generic_dashboard_cta_opens_wizard_with_no_selected_mode(): void
    {
        // No query params at all — the generic "Book a session" CTA.
        Livewire::actingAs($this->student())
            ->test('frontend.booking.booking-wizard')
            ->assertSet('type', null)
            ->assertSet('step', 1);
    }

    // ── 2. Explicit CTAs preselect correctly ──────────────────────────────

    public function test_free_demo_cta_preselects_free_demo_and_skips_the_mode_step(): void
    {
        Livewire::actingAs($this->student())
            ->withQueryParams(['type' => 'free_demo'])
            ->test('frontend.booking.booking-wizard')
            ->assertSet('type', 'free_demo')
            ->assertSet('step', 2);
    }

    public function test_paid_lesson_cta_preselects_paid_lesson_and_skips_the_mode_step(): void
    {
        $priced = $this->paidTypeWithPrice();

        Livewire::actingAs($this->student())
            ->withQueryParams(['type' => 'paid_one_to_one'])
            ->test('frontend.booking.booking-wizard')
            ->assertSet('type', 'paid_one_to_one')
            ->assertSet('step', 2);
    }

    public function test_removed_type_key_is_a_no_op_when_selected_directly_on_the_component(): void
    {
        Livewire::actingAs($this->student())
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'counselling')
            ->assertSet('type', null)
            ->assertSet('step', 1);
    }

    // ── 3. Free Demo never enters payment; Paid Single creates one booking ─

    public function test_free_demo_booking_never_requires_payment(): void
    {
        $student = $this->student();
        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        $component = Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->call('submit');

        $this->assertFalse($component->get('result')['requires_payment']);
    }

    public function test_paid_single_session_creates_exactly_one_booking(): void
    {
        $priced = $this->paidTypeWithPrice();
        $student = $this->student();
        $this->assignBillingCountry($student, $priced['country']);
        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectBillingMode', 'single')
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->call('submit');

        $this->assertSame(1, Booking::query()->where('student_id', $student->id)->count());
    }

    // ── 4. Recurring: Daily/Weekly create valid occurrences ──────────────

    public function test_paid_recurring_weekly_creates_valid_occurrences(): void
    {
        $priced = $this->paidTypeWithPrice();
        $student = $this->student();
        $this->assignBillingCountry($student, $priced['country']);
        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        $component = Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectBillingMode', 'recurring')
            ->call('selectFrequency', 'weekly', 3)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->call('submit');

        $result = $component->get('result');
        $this->assertTrue($result['recurring']);
        $this->assertCount(3, $result['bookings']);

        $starts = Booking::query()->where('student_id', $student->id)->orderBy('starts_at')->pluck('starts_at');
        $this->assertCount(3, $starts);
        $this->assertSame(7, (int) $starts[0]->diffInDays($starts[1]));
        $this->assertSame(7, (int) $starts[1]->diffInDays($starts[2]));
    }

    public function test_paid_recurring_daily_creates_valid_occurrences(): void
    {
        $priced = $this->paidTypeWithPrice();
        $student = $this->student();
        $this->assignBillingCountry($student, $priced['country']);
        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        $component = Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectBillingMode', 'recurring')
            ->call('selectFrequency', 'daily', 3)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->call('submit');

        $result = $component->get('result');
        $this->assertTrue($result['recurring']);
        $this->assertCount(3, $result['bookings']);

        $starts = Booking::query()->where('student_id', $student->id)->orderBy('starts_at')->pluck('starts_at');
        $this->assertCount(3, $starts);
        $this->assertSame(1, (int) $starts[0]->diffInDays($starts[1]));
        $this->assertSame(1, (int) $starts[1]->diffInDays($starts[2]));
    }

    public function test_recurring_series_reuses_the_same_instructor_for_every_occurrence(): void
    {
        $priced = $this->paidTypeWithPrice();
        $student = $this->student();
        $this->assignBillingCountry($student, $priced['country']);
        $start = now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String();

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'paid_one_to_one')
            ->call('selectSubject', 'maths')
            ->call('selectGrade', 5)
            ->call('selectBillingMode', 'recurring')
            ->call('selectFrequency', 'weekly', 2)
            ->call('selectDate', now('UTC')->addDays(3)->toDateString())
            ->call('selectSlot', $start)
            ->call('submit');

        $instructorIds = Booking::query()->where('student_id', $student->id)->pluck('instructor_id')->unique();
        $this->assertCount(1, $instructorIds);
    }

    // ── 5. Recurrence rules (service-level, not just UI) ──────────────────

    public function test_recurrence_cannot_be_selected_for_free_demo_via_service(): void
    {
        $this->actingAs($this->student());

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Recurring sessions are only available for paid booking types.');

        app(WizardBookingServiceInterface::class)->bookRecurring(
            new WizardBookingData(
                typeKey: 'free_demo',
                subject: 'maths',
                grade: 5,
                startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
                timezone: 'UTC',
            ),
            new RecurrenceData(2, RecurrenceFrequency::Weekly),
        );
    }

    public function test_recurrence_cannot_be_selected_for_free_demo_via_student_booking_service(): void
    {
        $student = $this->student();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Recurring sessions are only available for paid booking types.');

        app(StudentBookingServiceInterface::class)->bookRecurring(
            new StudentBookingData(
                typeKey: 'free_demo',
                studentId: $student->id,
                teacherId: $this->teacher->id,
                startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
                subject: 'maths',
                grade: 5,
            ),
            new RecurrenceData(2, RecurrenceFrequency::Weekly),
        );
    }

    public function test_missing_paid_recurrence_frequency_is_rejected_by_the_api(): void
    {
        $priced = $this->paidTypeWithPrice();
        $student = $this->student();
        $this->assignBillingCountry($student, $priced['country']);

        $this->actingAs($student)
            ->postJson('/dashboard/bookings', [
                'type' => 'paid_one_to_one',
                'teacher_id' => $this->teacher->id,
                'starts_at' => now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String(),
                'subject' => 'maths',
                'grade' => 5,
                'recurring' => true,
                'occurrences' => 3,
                // frequency deliberately omitted
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['frequency']);
    }

    // ── 6. Removed type keys are rejected at the authoritative service ────

    public function test_removed_type_key_is_rejected_by_the_wizard_service(): void
    {
        $this->expectException(BookingException::class);

        app(WizardBookingServiceInterface::class)->book(new WizardBookingData(
            typeKey: 'counselling',
            subject: 'maths',
            grade: 5,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            timezone: 'UTC',
        ));
    }

    public function test_removed_type_key_is_rejected_by_the_authoritative_booking_service(): void
    {
        $student = $this->student();

        $this->expectException(BookingException::class);

        app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'webinar',
            studentId: $student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 5,
        ));
    }
}
