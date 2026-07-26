<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Types\FreeDemoType;
use App\Booking\Types\PaidOneToOneType;
use App\Earnings\Services\DemoConversionIncentiveEligibilityResolver;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Events\LessonCompleted;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\DemoConversionIncentiveAward;
use App\Models\InstructorCompensationAgreement;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;
use App\Settings\DemoConversionIncentiveSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * GAP-008 requirement #3 — every eligibility gate individually, so a
 * future regression can never silently widen the incentive boundary.
 * LessonCompleted is faked here so completing a fixture lesson never
 * triggers the real listener (CheckDemoConversionIncentiveOnLessonCompleted)
 * — this file tests the resolver in isolation; the end-to-end listener/
 * service flow (including the auto-award side effect this would
 * otherwise create) is covered by DemoConversionIncentiveServiceTest.
 */
final class DemoConversionIncentiveEligibilityResolverTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lessons;

    private DemoConversionIncentiveEligibilityResolver $resolver;

    private User $student;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([LessonCompleted::class]);

        $this->lessons = app(LessonLifecycleServiceInterface::class);
        $this->resolver = app(DemoConversionIncentiveEligibilityResolver::class);

        $this->student = User::factory()->create();
        $this->instructor = User::factory()->create();

        $this->enableRule();
    }

    private function enableRule(array $overrides = []): void
    {
        $settings = app(DemoConversionIncentiveSettings::class);
        $settings->enabled = $overrides['enabled'] ?? true;
        $settings->conversion_window_days = $overrides['conversion_window_days'] ?? 7;
        $settings->min_completed_paid_lessons = $overrides['min_completed_paid_lessons'] ?? 1;
        $settings->bonus_amount_minor = $overrides['bonus_amount_minor'] ?? 20000;
        $settings->bonus_currency_code = $overrides['bonus_currency_code'] ?? 'INR';
        $settings->max_awards_per_pair = $overrides['max_awards_per_pair'] ?? 1;
        $settings->applicable_country_ids = $overrides['applicable_country_ids'] ?? [];
        $settings->applicable_subject_ids = $overrides['applicable_subject_ids'] ?? [];
        $settings->save();
    }

    private function demoBookingType(): BookingType
    {
        return BookingType::query()->firstOrCreate(
            ['key' => FreeDemoType::KEY],
            ['name' => 'Free Demo', 'duration_minutes' => 30, 'is_paid' => false, 'is_active' => true],
        );
    }

    private function paidBookingType(): BookingType
    {
        return BookingType::query()->firstOrCreate(
            ['key' => PaidOneToOneType::KEY],
            ['name' => 'Paid 1-to-1', 'duration_minutes' => 60, 'is_paid' => true, 'is_active' => true],
        );
    }

    private function completedDemoLesson(?User $student = null, ?User $instructor = null): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => $this->demoBookingType()->id,
            'student_id' => ($student ?? $this->student)->id,
            'instructor_id' => ($instructor ?? $this->instructor)->id,
            'payment_status' => BookingPaymentStatus::NotRequired,
        ]);

        $lesson = $this->lessons->createFromBooking($booking);

        return $this->lessons->complete($lesson, override: true);
    }

    private function completedPaidLesson(?User $student = null, ?User $instructor = null, ?string $subjectId = null): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => $this->paidBookingType()->id,
            'student_id' => ($student ?? $this->student)->id,
            'instructor_id' => ($instructor ?? $this->instructor)->id,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        $lesson = $this->lessons->createFromBooking($booking);

        if ($subjectId !== null) {
            $lesson->fill(['subject_id' => $subjectId])->save();
        }

        $this->ensureAgreementFor($lesson->instructor_id);

        return $this->lessons->complete($lesson->fresh(), override: true);
    }

    private function ensureAgreementFor(int $instructorId): void
    {
        if (InstructorCompensationAgreement::query()->where('instructor_id', $instructorId)->where('status', 'active')->exists()) {
            return;
        }

        InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructorId,
            'amount_minor' => 80000,
            'currency_code' => 'INR',
            'effective_from' => now()->subMonth(),
        ]);
    }

    private function makeSubject(): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'test-category'], ['name' => 'Test Category']);

        return Subject::query()->create([
            'academic_category_id' => $category->id,
            'name' => 'Test Subject '.uniqid(),
            'slug' => 'test-subject-'.uniqid(),
            'status' => 'active',
        ]);
    }

    // ── Eligible baseline ──────────────────────────────────────────────

    public function test_a_paid_lesson_completed_shortly_after_a_completed_demo_is_eligible(): void
    {
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertTrue($result->eligible);
        $this->assertNull($result->reason);
        $this->assertNotNull($result->demoLesson);
    }

    // ── Rule disabled ──────────────────────────────────────────────────

    public function test_ineligible_when_the_rule_is_disabled(): void
    {
        $this->enableRule(['enabled' => false]);
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('rule_disabled', $result->reason);
    }

    // ── Incomplete demo / paid lesson ───────────────────────────────────

    public function test_ineligible_when_no_demo_exists_for_the_pair(): void
    {
        $paidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('no_completed_demo_for_pair', $result->reason);
    }

    public function test_ineligible_when_the_paid_lesson_is_not_completed(): void
    {
        $this->completedDemoLesson();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => $this->paidBookingType()->id,
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'payment_status' => BookingPaymentStatus::Paid,
        ]);
        $paidLesson = $this->lessons->createFromBooking($booking);

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('paid_lesson_not_completed', $result->reason);
    }

    // ── Different student/instructor ────────────────────────────────────

    public function test_ineligible_when_the_demo_belongs_to_a_different_instructor(): void
    {
        $otherInstructor = User::factory()->create();
        $this->completedDemoLesson(instructor: $otherInstructor);
        $paidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('no_completed_demo_for_pair', $result->reason);
    }

    public function test_ineligible_when_the_demo_belongs_to_a_different_student(): void
    {
        $otherStudent = User::factory()->create();
        $this->completedDemoLesson(student: $otherStudent);
        $paidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('no_completed_demo_for_pair', $result->reason);
    }

    // ── Not a financially-eligible paid lesson ──────────────────────────

    public function test_ineligible_when_the_booking_payment_is_not_settled(): void
    {
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();

        // Simulate a payment that was reverted after completion (e.g. a
        // refund) — the resolver must re-check the CURRENT state, not
        // just whatever was true at lesson-creation time.
        $paidLesson->booking->fill(['payment_status' => BookingPaymentStatus::Refunded])->save();

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('paid_booking_not_financially_settled', $result->reason);
    }

    public function test_ineligible_when_the_booking_type_is_not_a_paid_type(): void
    {
        $this->completedDemoLesson();
        // A second demo-typed booking, but with payment marked Paid — the
        // TYPE (is_paid) gate must still reject it.
        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => $this->demoBookingType()->id,
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'payment_status' => BookingPaymentStatus::Paid,
        ]);
        $lesson = $this->lessons->createFromBooking($booking);
        $paidLesson = $this->lessons->complete($lesson->fresh(), override: true);

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('not_a_paid_lesson_type', $result->reason);
    }

    // ── Conversion window ────────────────────────────────────────────────

    public function test_ineligible_when_outside_the_conversion_window(): void
    {
        $this->enableRule(['conversion_window_days' => 1]);
        $demo = $this->completedDemoLesson();
        $demo->fill(['completed_at' => now()->subDays(5)])->save();

        $paidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('outside_conversion_window', $result->reason);
    }

    public function test_eligible_at_the_exact_edge_of_the_conversion_window(): void
    {
        $this->enableRule(['conversion_window_days' => 7]);
        $demo = $this->completedDemoLesson();
        $demo->fill(['completed_at' => now()->subDays(7)])->save();

        $paidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertTrue($result->eligible);
    }

    // ── Minimum completed paid lessons ───────────────────────────────────

    public function test_ineligible_when_the_minimum_paid_lesson_count_is_not_yet_met(): void
    {
        $this->enableRule(['min_completed_paid_lessons' => 2]);
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('minimum_paid_lessons_not_met', $result->reason);
    }

    public function test_eligible_once_the_minimum_paid_lesson_count_is_met(): void
    {
        $this->enableRule(['min_completed_paid_lessons' => 2]);
        $this->completedDemoLesson();
        $this->completedPaidLesson();
        $secondPaidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($secondPaidLesson->fresh());

        $this->assertTrue($result->eligible);
    }

    // ── Country applicability ─────────────────────────────────────────────

    public function test_ineligible_when_the_instructors_country_is_not_in_the_applicable_list(): void
    {
        $country = Country::factory()->create();
        $this->enableRule(['applicable_country_ids' => [$country->id + 999999]]);
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('country_not_applicable', $result->reason);
    }

    public function test_eligible_when_the_instructors_country_is_in_the_applicable_list(): void
    {
        $country = Country::factory()->create();
        $this->instructor->profile->update(['country_id' => $country->id]);
        $this->enableRule(['applicable_country_ids' => [$country->id]]);
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertTrue($result->eligible);
    }

    // ── Subject applicability ─────────────────────────────────────────────

    public function test_ineligible_when_the_paid_lessons_subject_is_not_in_the_applicable_list(): void
    {
        $subject = $this->makeSubject();
        $this->enableRule(['applicable_subject_ids' => [$subject->id]]);
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('subject_not_applicable', $result->reason);
    }

    public function test_eligible_when_the_paid_lessons_subject_is_in_the_applicable_list(): void
    {
        $subject = $this->makeSubject();
        $this->enableRule(['applicable_subject_ids' => [$subject->id]]);
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson(subjectId: $subject->id);

        $result = $this->resolver->evaluate($paidLesson->fresh());

        $this->assertTrue($result->eligible);
    }

    // ── Max awards per pair ───────────────────────────────────────────────

    public function test_ineligible_once_the_award_limit_for_the_pair_is_reached(): void
    {
        $this->completedDemoLesson();
        $firstPaidLesson = $this->completedPaidLesson();

        DemoConversionIncentiveAward::factory()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'demo_booking_id' => $firstPaidLesson->booking_id,
            'demo_lesson_id' => $firstPaidLesson->id,
            'paid_booking_id' => $firstPaidLesson->booking_id,
            'paid_lesson_id' => $firstPaidLesson->id,
        ]);

        $secondPaidLesson = $this->completedPaidLesson();

        $result = $this->resolver->evaluate($secondPaidLesson->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('award_limit_reached', $result->reason);
    }
}
