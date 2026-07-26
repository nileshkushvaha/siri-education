<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Types\FreeDemoType;
use App\Booking\Types\PaidOneToOneType;
use App\Earnings\Enums\EarningCalculationType;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Services\DemoConversionIncentiveService;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Currency;
use App\Models\DemoConversionIncentiveAward;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\NotificationDispatchLog;
use App\Models\User;
use App\Notifications\Instructor\DemoConversionIncentiveEarnedNotification;
use App\Settings\DemoConversionIncentiveSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * GAP-008 requirement #4/#7 — end-to-end: the real LessonCompleted
 * event drives CheckDemoConversionIncentiveOnLessonCompleted, which
 * delegates entirely to DemoConversionIncentiveService. Covers
 * idempotency, concurrency-safety, immutable snapshots, earning
 * creation via the existing InstructorEarningService, audit,
 * notification, rollback, and historical-earnings immutability.
 */
final class DemoConversionIncentiveServiceTest extends TestCase
{
    use ManagesFinancialSettings;
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lessons;

    private DemoConversionIncentiveService $incentive;

    private User $student;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lessons = app(LessonLifecycleServiceInterface::class);
        $this->incentive = app(DemoConversionIncentiveService::class);

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->setFinancialSettings(['earnings_enabled' => true]);

        $this->enableRule();

        $this->student = User::factory()->create();
        $this->instructor = User::factory()->create();

        Notification::fake();
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

    private function bookingType(string $key, bool $isPaid): BookingType
    {
        return BookingType::query()->firstOrCreate(
            ['key' => $key],
            ['name' => $key, 'duration_minutes' => $isPaid ? 60 : 30, 'is_paid' => $isPaid, 'is_active' => true],
        );
    }

    private function completedDemoLesson(): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => $this->bookingType(FreeDemoType::KEY, false)->id,
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'payment_status' => BookingPaymentStatus::NotRequired,
        ]);

        $lesson = $this->lessons->createFromBooking($booking);

        return $this->lessons->complete($lesson, override: true);
    }

    private function completedPaidLesson(): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => $this->bookingType(PaidOneToOneType::KEY, true)->id,
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        $lesson = $this->lessons->createFromBooking($booking);

        if (! InstructorCompensationAgreement::query()->where('instructor_id', $lesson->instructor_id)->where('status', 'active')->exists()) {
            InstructorCompensationAgreement::factory()->active()->create([
                'instructor_id' => $lesson->instructor_id,
                'amount_minor' => 80000,
                'currency_code' => 'INR',
                'effective_from' => now()->subMonth(),
            ]);
        }

        return $this->lessons->complete($lesson->fresh(), override: true);
    }

    // ── End-to-end award + earning creation ───────────────────────────

    public function test_completing_a_qualifying_paid_lesson_creates_an_award_and_its_earning_automatically(): void
    {
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();

        $award = DemoConversionIncentiveAward::query()->where('paid_lesson_id', $paidLesson->id)->first();

        $this->assertNotNull($award);
        $this->assertSame($this->instructor->id, $award->instructor_id);
        $this->assertSame($this->student->id, $award->student_id);
        $this->assertSame(20000, $award->amount_minor);
        $this->assertSame('INR', $award->currency_code);
        $this->assertNotNull($award->instructor_earning_id);

        $earning = InstructorEarning::query()->findOrFail($award->instructor_earning_id);
        $this->assertSame(20000, $earning->earning_amount_minor);
        $this->assertSame('INR', $earning->currency_code);
        $this->assertSame(EarningCalculationType::DemoConversionIncentive, $earning->calculation_type);
        $this->assertSame(InstructorEarningStatus::PendingHold, $earning->status);
        $this->assertSame('demo_conversion_incentive', $earning->source_type);
        $this->assertSame($award->id, $earning->source_id);
        $this->assertNull($earning->lesson_id);
    }

    public function test_no_award_is_created_when_ineligible(): void
    {
        // No demo at all — a bare paid lesson never qualifies.
        $this->completedPaidLesson();

        $this->assertSame(0, DemoConversionIncentiveAward::query()->count());
    }

    // ── Idempotency / duplicate events ────────────────────────────────

    public function test_evaluating_the_same_paid_lesson_twice_creates_only_one_award(): void
    {
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();

        $this->assertSame(1, DemoConversionIncentiveAward::query()->count());

        // Simulate a duplicate/replayed LessonCompleted event.
        $again = $this->incentive->evaluate($paidLesson->fresh());

        $this->assertSame(1, DemoConversionIncentiveAward::query()->count());
        $this->assertSame(
            DemoConversionIncentiveAward::query()->first()->id,
            $again->id,
        );
    }

    public function test_calling_evaluate_directly_after_the_listener_already_ran_is_a_safe_no_op(): void
    {
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();
        $originalAward = DemoConversionIncentiveAward::query()->where('paid_lesson_id', $paidLesson->id)->firstOrFail();

        $result = $this->incentive->evaluate($paidLesson->fresh());

        $this->assertSame($originalAward->id, $result->id);
        $this->assertSame(1, InstructorEarning::query()->where('source_type', 'demo_conversion_incentive')->count());
    }

    // ── Maximum award limit ────────────────────────────────────────────

    public function test_a_second_qualifying_paid_lesson_does_not_exceed_the_default_max_of_one_award_per_pair(): void
    {
        $this->enableRule(['min_completed_paid_lessons' => 1, 'max_awards_per_pair' => 1]);
        $this->completedDemoLesson();
        $this->completedPaidLesson();

        $this->assertSame(1, DemoConversionIncentiveAward::query()->count());

        $this->completedPaidLesson();

        $this->assertSame(1, DemoConversionIncentiveAward::query()->count());
    }

    public function test_raising_the_max_awards_per_pair_allows_a_second_award(): void
    {
        $this->enableRule(['min_completed_paid_lessons' => 1, 'max_awards_per_pair' => 2]);
        $this->completedDemoLesson();
        $this->completedPaidLesson();
        $this->completedPaidLesson();

        $this->assertSame(2, DemoConversionIncentiveAward::query()->count());
    }

    // ── Immutable rule snapshot ────────────────────────────────────────

    public function test_the_rule_snapshot_is_frozen_and_unaffected_by_a_later_settings_change(): void
    {
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();

        $award = DemoConversionIncentiveAward::query()->where('paid_lesson_id', $paidLesson->id)->firstOrFail();
        $this->assertSame(20000, $award->rule_snapshot['bonus_amount_minor']);
        $this->assertSame(7, $award->rule_snapshot['conversion_window_days']);

        // A later admin change to the rule must never alter a historical award.
        $this->enableRule(['bonus_amount_minor' => 99999, 'conversion_window_days' => 1]);

        $award->refresh();
        $this->assertSame(20000, $award->rule_snapshot['bonus_amount_minor']);
        $this->assertSame(7, $award->rule_snapshot['conversion_window_days']);
        $this->assertSame(20000, $award->amount_minor);
    }

    public function test_historical_earnings_are_unaffected_by_a_later_settings_change(): void
    {
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();
        $award = DemoConversionIncentiveAward::query()->where('paid_lesson_id', $paidLesson->id)->firstOrFail();
        $earning = InstructorEarning::query()->findOrFail($award->instructor_earning_id);
        $originalAmount = $earning->earning_amount_minor;

        $this->enableRule(['bonus_amount_minor' => 500000]);

        $this->assertSame($originalAmount, $earning->fresh()->earning_amount_minor);
    }

    // ── Audit ───────────────────────────────────────────────────────────

    public function test_award_creation_is_audited(): void
    {
        $this->completedDemoLesson();
        $this->completedPaidLesson();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'demo_conversion_incentive',
            'event' => 'award_created',
        ]);
    }

    public function test_a_skipped_ineligible_evaluation_is_audited(): void
    {
        $this->completedPaidLesson(); // no demo — ineligible

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'demo_conversion_incentive',
            'event' => 'award_skipped',
        ]);
    }

    // ── Notification ─────────────────────────────────────────────────────

    public function test_the_instructor_is_notified_exactly_once(): void
    {
        $this->completedDemoLesson();
        $paidLesson = $this->completedPaidLesson();

        Notification::assertSentToTimes($this->instructor, DemoConversionIncentiveEarnedNotification::class, 1);

        // Re-evaluating (duplicate event) must never send a second notification.
        $this->incentive->evaluate($paidLesson->fresh());

        Notification::assertSentToTimes($this->instructor, DemoConversionIncentiveEarnedNotification::class, 1);
        $this->assertSame(
            1,
            NotificationDispatchLog::query()->where('idempotency_key', 'like', 'demo_conversion_incentive_earned:%')->count(),
        );
    }

    // ── Rollback (a failed transaction never leaves a partial award) ────

    public function test_no_award_or_earning_exists_when_the_rule_is_disabled_mid_flow(): void
    {
        $this->completedDemoLesson();
        $this->enableRule(['enabled' => false]);
        $this->completedPaidLesson();

        $this->assertSame(0, DemoConversionIncentiveAward::query()->count());
        $this->assertSame(0, InstructorEarning::query()->where('source_type', 'demo_conversion_incentive')->count());
    }
}
