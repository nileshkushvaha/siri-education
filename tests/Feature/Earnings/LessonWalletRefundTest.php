<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Booking\Enums\BookingPaymentStatus;
use App\Earnings\Contracts\LessonFinancialDispositionServiceInterface;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\LessonFinancialDispositionStatus;
use App\Earnings\Enums\LessonStudentDisposition;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Currency;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorEarning;
use App\Models\InstructorSettlementBatch;
use App\Models\Lesson;
use App\Models\LessonFinancialDisposition;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Wallet\Actions\ExecuteLessonWalletRefundAction;
use App\Wallet\Contracts\LessonWalletRefundServiceInterface;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletStatus;
use App\Wallet\Events\LessonRefundCompleted;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Database\Seeders\LessonPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * Wallet-only refund execution: eligibility, original-charge
 * resolution, ledger linkage, idempotency/concurrency, cancellation
 * dedup, failure safety, and override-after-refund reconciliation.
 */
class LessonWalletRefundTest extends TestCase
{
    use ManagesFinancialSettings;
    use RefreshDatabase;

    private LessonOutcomeServiceInterface $outcomes;

    private LessonWalletRefundServiceInterface $refunds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->refunds = app(LessonWalletRefundServiceInterface::class);

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        Http::fake(); // any gateway API call in these flows is a bug

        $this->setFinancialSettings([
            'earnings_enabled' => true,
            'financial_disposition_enabled' => true,
            'lesson_refund_execution_enabled' => true,
        ]);
    }

    // ── 1–3. Eligible refunds ────────────────────────────────────────

    public function test_instructor_no_show_creates_a_full_wallet_refund(): void
    {
        [$lesson, $payment] = $this->paidLessonWithCharge();
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);

        $this->artisan('lessons:process-refunds')
            ->expectsOutputToContain('Credited 1 wallet refund(s).')->assertSuccessful();

        $entry = $this->refundEntryFor($lesson);
        $this->assertSame($payment->amount_minor, $entry->amount_minor);
        $this->assertSame(WalletLedgerEntryType::Refund, $entry->entry_type);
        $this->assertSame($lesson->booking->student_id, $entry->user_id);
        $this->assertSame($payment->amount_minor, Wallet::query()->firstOrFail()->balance_minor);
    }

    public function test_approved_technical_issue_creates_a_wallet_refund(): void
    {
        [$lesson] = $this->paidLessonWithCharge();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::TechnicalIssue);

        $disposition = $this->dispositionFor($lesson);
        app(LessonFinancialDispositionServiceInterface::class)
            ->markReadyForRefund($disposition, $admin, 'Technical issue verified — full refund.');

        Event::fake([LessonRefundCompleted::class]);

        $resolved = $this->refunds->execute($disposition->refresh(), $admin);

        $this->assertSame(LessonFinancialDispositionStatus::Resolved, $resolved->processing_status);
        $this->assertNotNull($resolved->refund_ledger_entry_id);
        Event::assertDispatchedTimes(LessonRefundCompleted::class, 1);
    }

    public function test_approved_both_absent_disposition_creates_a_wallet_refund(): void
    {
        $this->setFinancialSettings(['both_absent_financial_policy' => 'refund']);
        [$lesson, $payment] = $this->paidLessonWithCharge();
        $this->outcomes->finalize($lesson, LessonOutcome::BothAbsent);

        $this->assertSame(1, $this->refunds->processReady());
        $this->assertSame($payment->amount_minor, $this->refundEntryFor($lesson)->amount_minor);
    }

    // ── 4–6. Ineligible outcomes ─────────────────────────────────────

    public function test_student_no_show_creates_no_refund_by_default(): void
    {
        [$lesson] = $this->paidLessonWithCharge();
        $this->outcomes->finalize($lesson, LessonOutcome::StudentNoShow);

        $this->assertSame(0, $this->refunds->processReady());
        $this->assertSame(0, WalletLedgerEntry::query()->count());
    }

    public function test_completed_lesson_creates_no_refund(): void
    {
        [$lesson] = $this->paidLessonWithCharge();
        $this->outcomes->finalize($lesson, LessonOutcome::Completed);

        $this->assertSame(0, $this->refunds->processReady());
        $this->assertSame(0, WalletLedgerEntry::query()->count());
    }

    public function test_unpaid_or_demo_lesson_creates_no_refund(): void
    {
        $lesson = $this->demoLesson();
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);

        // Demo bookings classify with the refund downgraded to none.
        $disposition = $this->dispositionFor($lesson);
        $this->assertNotSame(LessonStudentDisposition::FullWalletRefundRequired, $disposition->student_disposition);

        $this->assertSame(0, $this->refunds->processReady());
        $this->assertSame(0, WalletLedgerEntry::query()->count());
    }

    // ── 7–10. Amounts, currency, sources ─────────────────────────────

    public function test_gateway_paid_lesson_refunds_to_wallet_without_gateway_api_call(): void
    {
        [$lesson, $payment] = $this->paidLessonWithCharge(); // provider: razorpay
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);

        $this->assertSame(1, $this->refunds->processReady());

        Http::assertNothingSent();
        // The original gateway payment record is preserved untouched.
        $payment->refresh();
        $this->assertSame('razorpay', $payment->provider);
        $this->assertSame('captured', $payment->status->value);
    }

    public function test_wallet_paid_lesson_creates_a_separate_credit_without_modifying_the_debit(): void
    {
        [$lesson, $payment] = $this->paidLessonWithCharge(provider: 'wallet');
        $student = $lesson->booking->student;

        // Simulate the original wallet debit that paid for the lesson.
        $wallet = app(WalletService::class)->getOrCreateWallet($student, 'INR');
        app(WalletLedgerService::class)->credit($wallet, 499900, WalletLedgerEntryType::RechargeConfirmed, $student, idempotencyKey: 'seed-recharge');
        $debit = app(WalletLedgerService::class)->debit($wallet, 499900, WalletLedgerEntryType::BookingPayment, $student, idempotencyKey: 'seed-debit', sourceType: BookingPayment::class, sourceId: (string) $payment->id);

        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);
        $this->assertSame(1, $this->refunds->processReady());

        $refund = $this->refundEntryFor($lesson);
        $this->assertNotSame($debit->id, $refund->id);
        // Original debit untouched, byte for byte.
        $this->assertSame(499900, $debit->refresh()->amount_minor);
        $this->assertSame('debit', $debit->direction->value);
        $this->assertSame(499900, $wallet->refresh()->balance_minor); // 499900 − 499900 + 499900
    }

    public function test_refund_uses_the_original_paid_amount_and_currency(): void
    {
        [$lesson, $payment] = $this->paidLessonWithCharge(amountMinor: 123450);
        // Current pricing changed after the charge — must be ignored.
        $lesson->booking->update(['price' => '9999.00']);

        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);
        $this->assertSame(1, $this->refunds->processReady());

        $entry = $this->refundEntryFor($lesson);
        $this->assertSame(123450, $entry->amount_minor);
        $this->assertSame('INR', $entry->currency_code);
        $this->assertSame('INR', Wallet::query()->firstOrFail()->currency_code);
    }

    public function test_currency_mismatch_moves_to_manual_review(): void
    {
        [$lesson, $payment] = $this->paidLessonWithCharge();
        $payment->forceFill(['currency_code' => 'USD'])->save(); // inconsistent snapshot

        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);
        $this->assertSame(0, $this->refunds->processReady());

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonFinancialDispositionStatus::ManualReview, $disposition->processing_status);
        $this->assertSame('currency_mismatch', $disposition->reason_code);
        $this->assertSame(0, WalletLedgerEntry::query()->count());
    }

    // ── 12–14. Idempotency & cancellation dedup ──────────────────────

    public function test_duplicate_and_concurrent_execution_create_one_ledger_credit(): void
    {
        [$lesson] = $this->paidLessonWithCharge();
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);

        $this->assertSame(1, $this->refunds->processReady());
        $this->assertSame(0, $this->refunds->processReady()); // duplicate run

        // A stale copy racing the settled row (concurrent worker).
        $stale = LessonFinancialDisposition::query()->firstOrFail();
        $result = app(ExecuteLessonWalletRefundAction::class)->execute($stale);
        $this->assertFalse($result->credited);

        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', WalletLedgerEntryType::Refund)->count());
    }

    public function test_existing_cancellation_refund_is_not_duplicated(): void
    {
        [$lesson, $payment] = $this->paidLessonWithCharge();
        $student = $lesson->booking->student;

        // The cancellation flow already credited this charge back.
        $wallet = app(WalletService::class)->getOrCreateWallet($student, 'INR');
        $existing = app(WalletLedgerService::class)->credit(
            $wallet, $payment->amount_minor, WalletLedgerEntryType::Refund, $student,
            idempotencyKey: sprintf('cancellation-refund:%s', $payment->id),
        );

        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);
        $this->assertSame(0, $this->refunds->processReady());

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonFinancialDispositionStatus::Resolved, $disposition->processing_status);
        $this->assertSame('already_refunded_by_cancellation', $disposition->reason_code);
        $this->assertSame($existing->id, $disposition->refund_ledger_entry_id);
        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', WalletLedgerEntryType::Refund)->count());
    }

    // ── 15–17. Ledger integrity ──────────────────────────────────────

    public function test_ledger_entry_links_lesson_booking_source_transaction_and_disposition(): void
    {
        [$lesson, $payment] = $this->paidLessonWithCharge();
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);
        $this->refunds->processReady();

        $disposition = $this->dispositionFor($lesson);
        $entry = $this->refundEntryFor($lesson);

        $this->assertSame(LessonFinancialDisposition::class, $entry->source_type);
        $this->assertSame($disposition->id, $entry->source_id);
        $this->assertSame($lesson->id, $entry->metadata['lesson_id']);
        $this->assertSame($lesson->booking_id, $entry->metadata['booking_id']);
        $this->assertSame($payment->id, $entry->metadata['source_payment_id']);
        $this->assertSame(sprintf('lesson-refund:%s:v%d', $disposition->id, $disposition->version), $entry->idempotency_key);
        $this->assertNotNull($entry->balance_after_minor);
    }

    public function test_wallet_balance_changes_only_through_the_wallet_service(): void
    {
        [$lesson] = $this->paidLessonWithCharge();
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);
        $this->refunds->processReady();

        $wallet = Wallet::query()->firstOrFail();

        // The enforced model guard: any direct balance write explodes.
        $this->expectException(WalletException::class);
        $wallet->update(['balance_minor' => 0]);
    }

    public function test_original_payment_and_ledger_records_remain_unchanged(): void
    {
        [$lesson, $payment] = $this->paidLessonWithCharge();
        $originalAttributes = $payment->refresh()->getAttributes();

        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);
        $this->refunds->processReady();

        $payment->refresh();
        $this->assertSame($originalAttributes['amount_minor'], $payment->getAttributes()['amount_minor']);
        $this->assertSame($originalAttributes['status'], $payment->getAttributes()['status']);
        $this->assertSame($originalAttributes['provider_payment_id'], $payment->getAttributes()['provider_payment_id']);
    }

    // ── 18–20. Failure, resolution, audit ────────────────────────────

    public function test_failed_credit_does_not_mark_disposition_resolved(): void
    {
        [$lesson] = $this->paidLessonWithCharge();
        $student = $lesson->booking->student;
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);

        // A closed wallet makes the credit throw inside the transaction
        // (frozen wallets still accept credits by design — only closed
        // wallets reject them).
        $wallet = app(WalletService::class)->getOrCreateWallet($student, 'INR');
        $wallet->forceFill(['status' => WalletStatus::Closed])->save();

        $this->assertSame(0, $this->refunds->processReady());

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonFinancialDispositionStatus::Ready, $disposition->processing_status);
        $this->assertNull($disposition->refund_ledger_entry_id);
        $this->assertSame(0, WalletLedgerEntry::query()->where('entry_type', WalletLedgerEntryType::Refund)->count());
    }

    public function test_successful_credit_marks_disposition_resolved_and_stores_reference(): void
    {
        [$lesson] = $this->paidLessonWithCharge();
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);
        $this->refunds->processReady();

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonFinancialDispositionStatus::Resolved, $disposition->processing_status);
        $this->assertSame('refund_completed', $disposition->reason_code);
        $this->assertNotNull($disposition->refund_ledger_entry_id);
        $this->assertNotNull($disposition->refund_executed_at);
        $this->assertSame($this->refundEntryFor($lesson)->id, $disposition->refund_ledger_entry_id);
    }

    public function test_audit_entry_records_actor_amount_currency_and_reason(): void
    {
        [$lesson, $payment] = $this->paidLessonWithCharge();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);

        $this->refunds->execute($this->dispositionFor($lesson), $admin);

        $activity = Activity::query()
            ->where('log_name', 'instructor_earnings')
            ->where('event', 'lesson_refund_executed')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame($payment->amount_minor, $activity->properties->get('amount_minor'));
        $this->assertSame('INR', $activity->properties->get('currency'));
        $this->assertSame('refund_completed', $activity->properties->get('reason_code'));
        $this->assertSame(0, $activity->properties->get('balance_before_minor'));
        $this->assertSame($payment->amount_minor, $activity->properties->get('balance_after_minor'));
    }

    // ── 21–22. Overrides after refund & earning isolation ────────────

    public function test_outcome_override_after_refund_requires_manual_reconciliation_and_does_not_debit_wallet(): void
    {
        [$lesson, $payment] = $this->paidLessonWithCharge();
        $admin = $this->admin();
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);
        $this->refunds->processReady();

        $balanceAfterRefund = Wallet::query()->firstOrFail()->balance_minor;
        $refundEntryId = $this->dispositionFor($lesson)->refund_ledger_entry_id;

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::Completed, 'Instructor proved attendance.');

        $disposition = $this->dispositionFor($lesson);
        $this->assertSame(LessonFinancialDispositionStatus::ManualReview, $disposition->processing_status);
        $this->assertSame('refund_reconciliation_required', $disposition->reason_code);
        $this->assertTrue($disposition->admin_hold);
        // Refund preserved and linked; history preserved; wallet untouched.
        $this->assertSame($refundEntryId, $disposition->refund_ledger_entry_id);
        $this->assertCount(1, $disposition->history);
        $this->assertSame($balanceAfterRefund, Wallet::query()->firstOrFail()->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::query()->where('entry_type', WalletLedgerEntryType::Refund)->count());
    }

    public function test_no_instructor_earning_or_settlement_record_is_changed(): void
    {
        [$lesson] = $this->paidLessonWithCharge(withAgreement: true);
        // A completed sibling lesson's earning must stay untouched.
        $completed = $this->paidLessonWithCharge(withAgreement: true)[0];
        $this->outcomes->finalize($completed, LessonOutcome::Completed);
        $earning = InstructorEarning::query()->firstOrFail();

        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);
        $this->refunds->processReady();

        $this->assertSame(InstructorEarningStatus::PendingHold, $earning->refresh()->status);
        $this->assertSame(1, InstructorEarning::query()->count());
        $this->assertSame(0, InstructorSettlementBatch::query()->count());
    }

    public function test_disabled_execution_processes_nothing(): void
    {
        $this->setFinancialSettings(['lesson_refund_execution_enabled' => false]);
        [$lesson] = $this->paidLessonWithCharge();
        $this->outcomes->finalize($lesson, LessonOutcome::InstructorNoShow);

        $this->artisan('lessons:process-refunds')
            ->expectsOutputToContain('Credited 0 wallet refund(s).')->assertSuccessful();

        $this->assertSame(0, WalletLedgerEntry::query()->count());
        $this->assertSame(LessonFinancialDispositionStatus::Ready, $this->dispositionFor($lesson)->processing_status);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array{0: Lesson, 1: BookingPayment} */
    private function paidLessonWithCharge(int $amountMinor = 499900, string $provider = 'razorpay', bool $withAgreement = false): array
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => number_format($amountMinor / 100, 2, '.', ''),
            'currency' => 'INR',
            'payment_reference' => 'PAY-17F-'.fake()->unique()->bothify('####'),
        ]);

        $payment = BookingPayment::factory()->captured()->create([
            'booking_id' => $booking->id,
            'user_id' => $booking->student_id,
            'provider' => $provider,
            'amount_minor' => $amountMinor,
            'currency_code' => 'INR',
        ]);

        $lesson = app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);

        if ($withAgreement) {
            InstructorCompensationAgreement::factory()->active()->create([
                'instructor_id' => $lesson->instructor_id,
                'amount_minor' => 80000,
                'currency_code' => 'INR',
                'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
                'effective_from' => now()->subMonth(),
            ]);
        }

        return [$lesson, $payment];
    }

    private function demoLesson(): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'price' => null,
            'currency' => null,
        ]);

        return app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);
    }

    private function dispositionFor(Lesson $lesson): LessonFinancialDisposition
    {
        return LessonFinancialDisposition::query()->where('lesson_id', $lesson->id)->firstOrFail();
    }

    private function refundEntryFor(Lesson $lesson): WalletLedgerEntry
    {
        return WalletLedgerEntry::query()
            ->where('entry_type', WalletLedgerEntryType::Refund)
            ->where('metadata->lesson_id', $lesson->id)
            ->firstOrFail();
    }

    private function admin(): User
    {
        $this->seed(LessonPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('manager');

        return $admin;
    }
}
