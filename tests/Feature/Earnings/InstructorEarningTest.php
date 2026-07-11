<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Enums\EarningCalculationType;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Exceptions\EarningException;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstructorEarningTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lessons;

    private InstructorEarningServiceInterface $earnings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lessons = app(LessonLifecycleServiceInterface::class);
        $this->earnings = app(InstructorEarningServiceInterface::class);
    }

    // ── Creation ─────────────────────────────────────────────────────

    public function test_completed_paid_lesson_creates_exactly_one_pending_hold_earning(): void
    {
        $lesson = $this->completedPaidLesson(price: '499.00');

        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->first();

        $this->assertNotNull($earning);
        $this->assertSame(InstructorEarningStatus::PendingHold, $earning->status);
        $this->assertSame(EarningCalculationType::Percentage, $earning->calculation_type);
        // 70% of 49900 minor units, floored — integers end to end.
        $this->assertSame(49900, $earning->student_amount_minor);
        $this->assertSame(34930, $earning->earning_amount_minor);
        $this->assertSame(14970, $earning->platform_margin_minor);
        $this->assertSame($earning->student_amount_minor, $earning->earning_amount_minor + $earning->platform_margin_minor);
        $this->assertSame('INR', $earning->currency_code);
        $this->assertSame($lesson->instructor_id, $earning->instructor_id);
        $this->assertTrue($lesson->completed_at->addDays(7)->equalTo($earning->hold_until));
        $this->assertNull($earning->released_at);
    }

    public function test_captured_booking_payment_is_the_authoritative_student_amount(): void
    {
        $booking = Booking::factory()->confirmed()->paid(499.00, 'INR')->create();
        // Gateway captured a different amount than the legacy price column —
        // the integer gateway snapshot must win.
        BookingPayment::factory()->captured()->create([
            'booking_id' => $booking->id,
            'amount_minor' => 52500,
            'currency_code' => 'INR',
        ]);

        $lesson = $this->lessons->createFromBooking($booking);
        $this->lessons->complete($lesson, override: true);

        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(52500, $earning->student_amount_minor);
        $this->assertSame(36750, $earning->earning_amount_minor);
    }

    public function test_free_lesson_uses_fixed_rate_when_configured(): void
    {
        $this->settings(['default_fixed_amount_minor' => 15000, 'default_currency_code' => 'INR']);

        $lesson = $this->completedFreeLesson();

        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(EarningCalculationType::Fixed, $earning->calculation_type);
        $this->assertSame(15000, $earning->earning_amount_minor);
        $this->assertNull($earning->student_amount_minor);
        $this->assertNull($earning->platform_margin_minor);
        $this->assertSame('INR', $earning->currency_code);
    }

    public function test_free_lesson_without_fixed_rate_is_blocked_safely(): void
    {
        $lesson = $this->completedFreeLesson();

        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'instructor_earnings',
            'event' => 'earning_calculation_blocked',
        ]);
        // Idempotent re-run stays blocked, never guesses money.
        $this->assertNull($this->earnings->createFromLesson($lesson->refresh()));
    }

    public function test_fixed_rate_exceeding_student_amount_is_blocked(): void
    {
        $this->settings(['default_calculation_type' => 'fixed', 'default_fixed_amount_minor' => 99999999]);

        $this->completedPaidLesson(price: '499.00');

        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'earning_calculation_blocked']);
    }

    public function test_ineligible_lessons_never_create_earnings(): void
    {
        // Scheduled (not completed).
        $scheduled = Lesson::factory()->create();

        // Cancelled and no-show outcomes.
        $cancelled = Lesson::factory()->cancelled()->create();
        $studentNoShow = Lesson::factory()->create(['status' => 'student_no_show']);
        $instructorNoShow = Lesson::factory()->create(['status' => 'instructor_no_show']);
        $bothNoShow = Lesson::factory()->create(['status' => 'both_no_show']);
        $disputed = Lesson::factory()->create(['status' => 'disputed']);

        // Option B late terminal payment: booking cancelled, payment
        // reached Paid afterwards — the booking is not confirmed/completed.
        $lateTerminal = Lesson::factory()->completed()->create([
            'booking_id' => Booking::factory()->cancelled()->paid()->create()->id,
        ]);

        // Completed lesson whose booking payment is still pending.
        $unpaid = Lesson::factory()->completed()->create([
            'booking_id' => Booking::factory()->confirmed()->create(['payment_status' => BookingPaymentStatus::Pending])->id,
        ]);

        foreach ([$scheduled, $cancelled, $studentNoShow, $instructorNoShow, $bothNoShow, $disputed, $lateTerminal, $unpaid] as $lesson) {
            $this->assertNull($this->earnings->createFromLesson($lesson));
        }

        $this->assertSame(0, InstructorEarning::query()->count());
    }

    public function test_duplicate_completion_does_not_duplicate_earning(): void
    {
        $lesson = $this->completedPaidLesson();

        // Idempotent lesson re-completion and direct service re-calls.
        $this->lessons->complete($lesson->refresh(), override: true);
        $this->earnings->createFromLesson($lesson->refresh());

        $this->assertSame(1, InstructorEarning::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_earnings_kill_switch_stops_creation(): void
    {
        $this->settings(['earnings_enabled' => false]);

        $lesson = $this->completedPaidLesson();

        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertNull($this->earnings->createFromLesson($lesson));
    }

    // ── Hold / release ───────────────────────────────────────────────

    public function test_release_command_releases_only_due_eligible_earnings(): void
    {
        $due = InstructorEarning::factory()->heldPastDue()->create();
        $notYetDue = InstructorEarning::factory()->create(['hold_until' => now()->addDays(3)]);
        $disputed = InstructorEarning::factory()->create(['status' => InstructorEarningStatus::DisputedHold, 'hold_until' => now()->subDay()]);
        $reversed = InstructorEarning::factory()->create(['status' => InstructorEarningStatus::Reversed, 'hold_until' => now()->subDay()]);

        $this->artisan('instructor-earnings:release')
            ->expectsOutputToContain('Released 1 earning(s).')
            ->assertSuccessful();

        $due->refresh();
        $this->assertSame(InstructorEarningStatus::Releasable, $due->status);
        $this->assertNotNull($due->released_at);

        $this->assertSame(InstructorEarningStatus::PendingHold, $notYetDue->refresh()->status);
        $this->assertSame(InstructorEarningStatus::DisputedHold, $disputed->refresh()->status);
        $this->assertSame(InstructorEarningStatus::Reversed, $reversed->refresh()->status);

        // Idempotent: nothing left to release.
        $this->artisan('instructor-earnings:release')
            ->expectsOutputToContain('Released 0 earning(s).')
            ->assertSuccessful();
    }

    public function test_release_command_respects_auto_release_setting(): void
    {
        $this->settings(['auto_release_enabled' => false]);
        $due = InstructorEarning::factory()->heldPastDue()->create();

        $this->artisan('instructor-earnings:release')
            ->expectsOutputToContain('Released 0 earning(s).')
            ->assertSuccessful();

        $this->assertSame(InstructorEarningStatus::PendingHold, $due->refresh()->status);
    }

    public function test_manual_release_before_hold_lapses_requires_override(): void
    {
        $earning = InstructorEarning::factory()->create(['hold_until' => now()->addDays(5)]);

        try {
            $this->earnings->release($earning);
            $this->fail('Expected early release without override to be rejected.');
        } catch (EarningException) {
            // expected
        }

        $earning = $this->earnings->release($earning, override: true);
        $this->assertSame(InstructorEarningStatus::Releasable, $earning->status);
    }

    // ── Dispute / cancellation sync ──────────────────────────────────

    public function test_disputing_a_completed_lesson_parks_and_resolution_restores_the_earning(): void
    {
        $lesson = $this->completedPaidLesson();
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $this->lessons->dispute($lesson->refresh(), $lesson->student, 'Lesson quality issue.');
        $this->assertSame(InstructorEarningStatus::DisputedHold, $earning->refresh()->status);

        // Dispute resolved in the instructor's favor: lesson re-completed.
        $this->lessons->complete($lesson->refresh(), override: true);
        $this->assertSame(InstructorEarningStatus::PendingHold, $earning->refresh()->status);
    }

    public function test_cancelling_a_disputed_lesson_reverses_the_earning(): void
    {
        $lesson = $this->completedPaidLesson();
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $this->lessons->dispute($lesson->refresh(), $lesson->student, 'Never delivered.');
        $this->lessons->cancel($lesson->refresh());

        $earning->refresh();
        $this->assertSame(InstructorEarningStatus::Reversed, $earning->status);
        $this->assertNotNull($earning->reversed_at);
    }

    // ── Visibility / boundaries ──────────────────────────────────────

    public function test_earning_serialization_hides_student_amount_margin_and_internals(): void
    {
        $lesson = $this->completedPaidLesson();
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $serialized = $earning->toArray();

        $this->assertArrayNotHasKey('student_amount_minor', $serialized);
        $this->assertArrayNotHasKey('platform_margin_minor', $serialized);
        $this->assertArrayNotHasKey('calculation_value', $serialized);
        $this->assertArrayNotHasKey('notes', $serialized);
        $this->assertArrayNotHasKey('metadata', $serialized);
        // The instructor's own amount stays visible.
        $this->assertArrayHasKey('earning_amount_minor', $serialized);

        $json = json_encode($serialized);
        $this->assertStringNotContainsString('razorpay', $json);
        $this->assertStringNotContainsString('wallet', $json);
    }

    public function test_earning_flow_never_touches_student_wallets(): void
    {
        $lesson = $this->completedPaidLesson();
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $this->earnings->release($earning, override: true);

        $this->assertSame(0, Wallet::query()->count());
        $this->assertSame(0, WalletLedgerEntry::query()->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function settings(array $overrides): void
    {
        $settings = app(InstructorEarningSettings::class);

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }

    private function completedPaidLesson(string $price = '499.00'): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => $price,
            'currency' => 'INR',
        ]);

        $lesson = $this->lessons->createFromBooking($booking);

        return $this->lessons->complete($lesson, override: true);
    }

    private function completedFreeLesson(): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'payment_status' => BookingPaymentStatus::NotRequired,
            'price' => null,
            'currency' => null,
        ]);

        $lesson = $this->lessons->createFromBooking($booking);

        return $this->lessons->complete($lesson, override: true);
    }
}
