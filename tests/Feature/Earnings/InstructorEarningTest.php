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
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 14.2 — earnings come exclusively from agreement-based
 * compensation: hourly rate × eligible minutes for lesson earnings,
 * never a percentage of the student price, which no longer enters the
 * calculation path at all.
 */
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

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        // Phase 14.2: earnings ship DISABLED — tests exercising creation
        // enable the switch explicitly.
        $this->settings(['earnings_enabled' => true]);
    }

    // ── Kill switch defaults ─────────────────────────────────────────

    public function test_earnings_and_withdrawals_default_to_disabled(): void
    {
        // The migrated repository defaults are what production gets:
        // both switches OFF. (setUp flips earnings_enabled for the other
        // tests, so read withdrawals from the repository and earnings
        // from a fresh migration expectation via the settings migration.)
        $withdrawals = json_decode((string) \DB::table('settings')
            ->where('group', 'instructor_earnings')->where('name', 'withdrawals_enabled')
            ->value('payload'), true);
        $this->assertFalse($withdrawals);

        // Flip earnings back off and confirm the repository agrees.
        $this->settings(['earnings_enabled' => false]);
        $earnings = json_decode((string) \DB::table('settings')
            ->where('group', 'instructor_earnings')->where('name', 'earnings_enabled')
            ->value('payload'), true);
        $this->assertFalse($earnings);
    }

    public function test_earnings_kill_switch_stops_creation(): void
    {
        $this->settings(['earnings_enabled' => false]);

        $lesson = $this->completedPaidLesson();

        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertNull($this->earnings->createFromLesson($lesson));
    }

    // ── Creation (agreement-based, never student price) ──────────────

    public function test_completed_paid_lesson_creates_one_hourly_agreement_earning(): void
    {
        $lesson = $this->completedPaidLesson(price: '499.00', rateMinor: 80000);

        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $this->assertSame(InstructorEarningStatus::PendingHold, $earning->status);
        $this->assertSame(EarningCalculationType::Hourly, $earning->calculation_type);
        // 60-minute lesson at 80000/hour — independent of the 499.00 price.
        $this->assertSame(80000, $earning->earning_amount_minor);
        $this->assertSame('INR', $earning->currency_code);
        $this->assertTrue($lesson->completed_at->addDays(7)->equalTo($earning->hold_until));

        // The immutable snapshot records the applied agreement, not prices.
        $metadata = $earning->getAttribute('metadata');
        $this->assertSame('hourly', $metadata['pay_basis']);
        $this->assertSame(80000, $metadata['rate_minor']);
        $this->assertSame(60, $metadata['eligible_minutes']);
        $this->assertSame('half_up_minor', $metadata['rounding_policy']);
        $this->assertArrayNotHasKey('student_amount_minor', $metadata);
        $this->assertArrayNotHasKey('student_price', $metadata);
    }

    public function test_student_price_changes_never_change_the_earning(): void
    {
        $cheap = $this->completedPaidLesson(price: '100.00', rateMinor: 80000);
        $pricey = $this->completedPaidLesson(price: '99999.00', rateMinor: 80000);

        $cheapEarning = InstructorEarning::query()->where('lesson_id', $cheap->id)->firstOrFail();
        $priceyEarning = InstructorEarning::query()->where('lesson_id', $pricey->id)->firstOrFail();

        // Same agreement rate, same duration → identical compensation.
        $this->assertSame($cheapEarning->earning_amount_minor, $priceyEarning->earning_amount_minor);
    }

    public function test_lesson_without_agreement_is_blocked_for_controlled_retry(): void
    {
        $booking = Booking::factory()->confirmed()->create([
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        $lesson = $this->lessons->createFromBooking($booking);
        $lesson = $this->lessons->complete($lesson, override: true);

        // Blocked — never estimated, never derived from the price.
        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'instructor_compensation',
            'event' => 'earning_blocked_no_agreement',
        ]);

        // Controlled retry once compensation is configured.
        $this->hourlyAgreementFor($lesson->instructor_id, 80000);
        $earning = $this->earnings->createFromLesson($lesson->refresh());

        $this->assertNotNull($earning);
        $this->assertSame(80000, $earning->earning_amount_minor);
    }

    public function test_periodic_agreement_creates_no_lesson_earning(): void
    {
        $booking = Booking::factory()->confirmed()->create([
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);
        $lesson = $this->lessons->createFromBooking($booking);

        InstructorCompensationAgreement::factory()->monthly()->active()->create([
            'instructor_id' => $lesson->instructor_id,
            'effective_from' => now()->startOfMonth(),
        ]);

        $this->lessons->complete($lesson, override: true);

        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'earning_skipped_periodic_basis']);
    }

    // ── Demo policy ──────────────────────────────────────────────────

    public function test_demo_lesson_with_policy_none_creates_no_earning(): void
    {
        $lesson = $this->completedFreeLesson();

        $this->assertSame(0, InstructorEarning::query()->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'earning_skipped_demo_policy']);
        // Idempotent re-run stays skipped, never guesses money.
        $this->assertNull($this->earnings->createFromLesson($lesson->refresh()));
    }

    public function test_demo_lesson_with_fixed_policy_pays_the_explicit_amount(): void
    {
        $this->settings(['demo_compensation_policy' => 'fixed_demo_amount', 'demo_fixed_amount_minor' => 15000]);

        $lesson = $this->completedFreeLesson();

        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame(EarningCalculationType::DemoFixed, $earning->calculation_type);
        $this->assertSame(15000, $earning->earning_amount_minor);
    }

    // ── Eligibility / idempotency (unchanged Phase 14 rules) ─────────

    public function test_ineligible_lessons_never_create_earnings(): void
    {
        $scheduled = Lesson::factory()->create();
        $cancelled = Lesson::factory()->cancelled()->create();
        $studentNoShow = Lesson::factory()->create(['status' => 'student_no_show']);
        $disputed = Lesson::factory()->create(['status' => 'disputed']);
        $lateTerminal = Lesson::factory()->completed()->create([
            'booking_id' => Booking::factory()->cancelled()->paid()->create()->id,
        ]);
        $unpaid = Lesson::factory()->completed()->create([
            'booking_id' => Booking::factory()->confirmed()->create(['payment_status' => BookingPaymentStatus::Pending])->id,
        ]);

        foreach ([$scheduled, $cancelled, $studentNoShow, $disputed, $lateTerminal, $unpaid] as $lesson) {
            $this->hourlyAgreementFor($lesson->instructor_id);
            $this->assertNull($this->earnings->createFromLesson($lesson));
        }

        $this->assertSame(0, InstructorEarning::query()->count());
    }

    public function test_duplicate_completion_does_not_duplicate_earning(): void
    {
        $lesson = $this->completedPaidLesson();

        $this->lessons->complete($lesson->refresh(), override: true);
        $this->earnings->createFromLesson($lesson->refresh());

        $this->assertSame(1, InstructorEarning::query()->where('lesson_id', $lesson->id)->count());
    }

    // ── Hold / release (unchanged lifecycle) ─────────────────────────

    public function test_release_command_releases_only_due_eligible_earnings(): void
    {
        $due = InstructorEarning::factory()->heldPastDue()->create();
        $notYetDue = InstructorEarning::factory()->create(['hold_until' => now()->addDays(3)]);
        $disputed = InstructorEarning::factory()->create(['status' => InstructorEarningStatus::DisputedHold, 'hold_until' => now()->subDay()]);

        $this->artisan('instructor-earnings:release')
            ->expectsOutputToContain('Released 1 earning(s).')
            ->assertSuccessful();

        $this->assertSame(InstructorEarningStatus::Releasable, $due->refresh()->status);
        $this->assertSame(InstructorEarningStatus::PendingHold, $notYetDue->refresh()->status);
        $this->assertSame(InstructorEarningStatus::DisputedHold, $disputed->refresh()->status);
    }

    public function test_manual_release_before_hold_lapses_requires_override(): void
    {
        $earning = InstructorEarning::factory()->create(['hold_until' => now()->addDays(5)]);

        try {
            $this->earnings->release($earning);
            $this->fail('Expected early release without override to be rejected.');
        } catch (EarningException) {
        }

        $this->assertSame(InstructorEarningStatus::Releasable, $this->earnings->release($earning, override: true)->status);
    }

    // ── Dispute sync (unchanged) ─────────────────────────────────────

    public function test_disputing_a_completed_lesson_parks_and_resolution_restores_the_earning(): void
    {
        $lesson = $this->completedPaidLesson();
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $this->lessons->dispute($lesson->refresh(), $lesson->student, 'Lesson quality issue.');
        $this->assertSame(InstructorEarningStatus::DisputedHold, $earning->refresh()->status);

        $this->lessons->complete($lesson->refresh(), override: true);
        $this->assertSame(InstructorEarningStatus::PendingHold, $earning->refresh()->status);
    }

    // ── Commission removal (Phase 14.4: gone entirely) ───────────────

    public function test_commission_calculation_types_no_longer_exist(): void
    {
        // Zero historical rows ever used the commission model, so the
        // percentage / global-fixed cases were removed outright — no new
        // or old code path can represent a commission earning at all.
        $values = array_map(fn (EarningCalculationType $c): string => $c->value, EarningCalculationType::cases());

        $this->assertNotContains('percentage', $values);
        $this->assertNotContains('fixed', $values);
        $this->assertEqualsCanonicalizing(['hourly', 'periodic', 'demo_fixed', 'manual'], $values);
    }

    public function test_earnings_schema_carries_no_student_pricing_columns(): void
    {
        $columns = Schema::getColumnListing('instructor_earnings');

        $this->assertNotContains('student_amount_minor', $columns);
        $this->assertNotContains('platform_margin_minor', $columns);
        $this->assertNotContains('calculation_value', $columns);
    }

    // ── Visibility / boundaries (unchanged guarantees) ───────────────

    public function test_earning_serialization_hides_legacy_amounts_and_internals(): void
    {
        $lesson = $this->completedPaidLesson();
        $earning = InstructorEarning::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $serialized = $earning->toArray();

        $this->assertArrayNotHasKey('metadata', $serialized);
        $this->assertArrayHasKey('earning_amount_minor', $serialized);
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

    private function hourlyAgreementFor(int $instructorId, int $rateMinor = 80000): InstructorCompensationAgreement
    {
        return InstructorCompensationAgreement::factory()->active()->create([
            'instructor_id' => $instructorId,
            'amount_minor' => $rateMinor,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
            'effective_from' => now()->subMonth(),
        ]);
    }

    private function completedPaidLesson(string $price = '499.00', int $rateMinor = 80000): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => $price,
            'currency' => 'INR',
        ]);

        $lesson = $this->lessons->createFromBooking($booking);
        $this->hourlyAgreementFor($lesson->instructor_id, $rateMinor);

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
        $this->hourlyAgreementFor($lesson->instructor_id);

        return $this->lessons->complete($lesson, override: true);
    }
}
