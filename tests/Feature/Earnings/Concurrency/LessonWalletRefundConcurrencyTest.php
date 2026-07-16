<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\Concurrency;

use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Support\FinancialFeatureToggle;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Lesson;
use App\Models\LessonFinancialDisposition;
use App\Models\WalletLedgerEntry;
use App\Settings\InstructorEarningSettings;
use App\Wallet\Enums\WalletLedgerEntryType;
use Illuminate\Support\Facades\Http;

/**
 * Phase 17V closure re-audit (Section 11) — the wallet-refund
 * exactly-once guarantee was previously only proven via sequential
 * stale-copy simulation (LessonWalletRefundTest::
 * test_duplicate_and_concurrent_execution_create_one_ledger_credit).
 * This is a TRUE cross-process race: two independent worker processes
 * call ExecuteLessonWalletRefundAction::execute() for the same
 * disposition at the same instant, over separate MySQL connections.
 */
class LessonWalletRefundConcurrencyTest extends ConcurrencyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(); // any gateway API call in this flow is a bug

        $settings = app(InstructorEarningSettings::class);
        $settings->financial_disposition_enabled = true;
        $settings->lesson_refund_execution_enabled = true;
        FinancialFeatureToggle::unguarded(fn () => $settings->save());
    }

    public function test_two_concurrent_refund_executors_converge_to_exactly_one_ledger_credit(): void
    {
        $lesson = $this->paidLessonWithCharge();
        app(LessonOutcomeServiceInterface::class)->finalize($lesson, LessonOutcome::InstructorNoShow);

        $disposition = LessonFinancialDisposition::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame('ready', $disposition->processing_status->value);

        $results = $this->race([
            ['execute-lesson-wallet-refund', ['disposition_id' => $disposition->id]],
            ['execute-lesson-wallet-refund', ['disposition_id' => $disposition->id]],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, 'Concurrent refund executor failed: '.json_encode($result));
        }

        $creditedCount = count(array_filter($results, static fn (array $r): bool => $r['result']['credited'] === true));
        $this->assertSame(
            1,
            $creditedCount,
            'Exactly one of the two concurrent refund executors must have credited the wallet — the other must observe the idempotent-repeat path.',
        );

        $this->assertSame(
            1,
            WalletLedgerEntry::query()->where('entry_type', WalletLedgerEntryType::Refund)->count(),
            'The refund must converge to exactly one ledger entry even when executed by two racing processes.',
        );
    }

    private function paidLessonWithCharge(int $amountMinor = 499900): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => number_format($amountMinor / 100, 2, '.', ''),
            'currency' => 'INR',
            'payment_reference' => 'PAY-17V-CONCURRENCY',
        ]);

        BookingPayment::factory()->captured()->create([
            'booking_id' => $booking->id,
            'user_id' => $booking->student_id,
            'provider' => 'razorpay',
            'amount_minor' => $amountMinor,
            'currency_code' => 'INR',
        ]);

        return app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);
    }
}
