<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\BookingPayment;
use App\Models\InstructorEarning;
use App\Models\InstructorSettlementBatch;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Settings\InstructorEarningSettings;
use App\Settings\PaymentGatewaySettings;
use App\Wallet\Enums\WalletLedgerDirection;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletLedgerStatus;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Financial reporting: terminology separation (§5/§7),
 * wallet liability and movements (§8/§12), payment collection and
 * success rate (§13), refunds (§15), instructor financials (§17/§18),
 * permission separation (§22), currency policy (§9), and the
 * zero-side-effect guarantee (§30).
 */
class FinancialReportsTest extends TestCase
{
    use RefreshDatabase;

    private FinancialReportsServiceInterface $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $this->reports = app(FinancialReportsServiceInterface::class);
        Http::fake(); // §30 — any provider network request from a report is a hard failure
    }

    private function manager(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    private function period(): ReportingPeriod
    {
        return ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, 'UTC');
    }

    private function filters(): ReportFilters
    {
        return new ReportFilters(period: $this->period());
    }

    /** @param array<string, mixed> $overrides */
    private function ledgerEntry(Wallet $wallet, WalletLedgerEntryType $type, WalletLedgerDirection $direction, int $amountMinor, array $overrides = []): WalletLedgerEntry
    {
        return WalletLedgerEntry::factory()->create(array_merge([
            'wallet_id' => $wallet->id,
            'entry_type' => $type,
            'direction' => $direction,
            'amount_minor' => $amountMinor,
            'status' => WalletLedgerStatus::Posted,
        ], $overrides));
    }

    // ── Wallet liability (§8) ─────────────────────────────────────────────

    public function test_current_wallet_liability_is_grouped_by_currency_and_as_of_now(): void
    {
        $admin = $this->manager();
        Wallet::factory()->create(['balance_minor' => 50000, 'available_balance_minor' => 50000]);
        Wallet::factory()->create(['balance_minor' => 25000, 'available_balance_minor' => 25000]);

        $summary = $this->reports->walletSummary($admin, $this->period(), $this->filters());

        $this->assertSame(75000, $summary->currentLiabilityByCurrency['INR']);
        $this->assertSame(2, $summary->walletCount);
        $this->assertSame(2, $summary->positiveBalanceWallets);
    }

    public function test_current_liability_is_unaffected_by_the_report_period(): void
    {
        $admin = $this->manager();
        Wallet::factory()->create(['balance_minor' => 50000, 'available_balance_minor' => 50000]);

        $today = ReportingPeriod::forPreset(ReportingPeriodPreset::Today, 'UTC');
        $month = ReportingPeriod::forPreset(ReportingPeriodPreset::PreviousMonth, 'UTC');

        $a = $this->reports->walletSummary($admin, $today, new ReportFilters(period: $today));
        $b = $this->reports->walletSummary($admin, $month, new ReportFilters(period: $month));

        $this->assertSame($a->currentLiabilityByCurrency, $b->currentLiabilityByCurrency);
    }

    public function test_balance_ledger_mismatch_detection(): void
    {
        $admin = $this->manager();
        $consistent = Wallet::factory()->create(['balance_minor' => 10000, 'available_balance_minor' => 10000]);
        $this->ledgerEntry($consistent, WalletLedgerEntryType::AdminAdjustment, WalletLedgerDirection::Credit, 10000, ['balance_after_minor' => 10000, 'available_after_minor' => 10000]);

        $drifted = Wallet::factory()->create(['balance_minor' => 99999, 'available_balance_minor' => 99999]);
        $this->ledgerEntry($drifted, WalletLedgerEntryType::AdminAdjustment, WalletLedgerDirection::Credit, 10000, ['balance_after_minor' => 10000, 'available_after_minor' => 10000]);

        $summary = $this->reports->walletSummary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->balanceMismatchCount);
    }

    // ── Wallet movements (§12) ────────────────────────────────────────────

    public function test_movements_keep_credit_types_distinct_and_directions_explicit(): void
    {
        $admin = $this->manager();
        $wallet = Wallet::factory()->create();
        $this->ledgerEntry($wallet, WalletLedgerEntryType::RechargeConfirmed, WalletLedgerDirection::Credit, 10000);
        $this->ledgerEntry($wallet, WalletLedgerEntryType::ReferralCredit, WalletLedgerDirection::Credit, 2000);
        $this->ledgerEntry($wallet, WalletLedgerEntryType::PromotionalCredit, WalletLedgerDirection::Credit, 1500);
        $this->ledgerEntry($wallet, WalletLedgerEntryType::Refund, WalletLedgerDirection::Credit, 5000);
        $this->ledgerEntry($wallet, WalletLedgerEntryType::BookingPayment, WalletLedgerDirection::Debit, 7000);
        $this->ledgerEntry($wallet, WalletLedgerEntryType::AdminAdjustment, WalletLedgerDirection::Debit, 300);

        $summary = $this->reports->walletSummary($admin, $this->period(), $this->filters());
        $key = fn (string $type, string $dir) => collect($summary->movements)->first(fn ($m) => $m['entryType'] === $type && $m['direction'] === $dir);

        $this->assertSame(10000, $key('recharge_confirmed', 'credit')['amountMinor']);
        $this->assertSame(2000, $key('referral_credit', 'credit')['amountMinor']);
        $this->assertSame(1500, $key('promotional_credit', 'credit')['amountMinor']);
        $this->assertSame(5000, $key('refund', 'credit')['amountMinor']);
        $this->assertSame(7000, $key('booking_payment', 'debit')['amountMinor']);
        $this->assertSame(300, $key('admin_adjustment', 'debit')['amountMinor']);
    }

    public function test_pending_and_failed_ledger_entries_are_excluded_from_movements(): void
    {
        $admin = $this->manager();
        $wallet = Wallet::factory()->create();
        $this->ledgerEntry($wallet, WalletLedgerEntryType::RechargeConfirmed, WalletLedgerDirection::Credit, 10000, ['status' => WalletLedgerStatus::Pending]);
        $this->ledgerEntry($wallet, WalletLedgerEntryType::RechargeConfirmed, WalletLedgerDirection::Credit, 20000, ['status' => WalletLedgerStatus::Failed]);

        $summary = $this->reports->walletSummary($admin, $this->period(), $this->filters());

        $this->assertEmpty($summary->movements);
    }

    // ── Payments (§13) ───────────────────────────────────────────────────

    public function test_payment_summary_counts_and_success_rate(): void
    {
        $admin = $this->manager();
        BookingPayment::factory()->create(['status' => 'captured', 'amount_minor' => 49900, 'currency_code' => 'INR', 'paid_at' => now()]);
        BookingPayment::factory()->create(['status' => 'captured', 'amount_minor' => 29900, 'currency_code' => 'INR', 'paid_at' => now()]);
        BookingPayment::factory()->create(['status' => 'failed', 'amount_minor' => 49900, 'currency_code' => 'INR', 'failed_at' => now()]);
        BookingPayment::factory()->create(['status' => 'pending', 'amount_minor' => 49900, 'currency_code' => 'INR']);

        $summary = $this->reports->paymentSummary($admin, $this->period(), $this->filters());

        $this->assertSame(4, $summary->attempts);
        $this->assertSame(2, $summary->captured);
        $this->assertSame(1, $summary->failed);
        $this->assertSame(1, $summary->pending);
        // 2 captured of 3 terminal — pending is not an outcome yet.
        $this->assertSame(66.7, $summary->successRate);
        $this->assertSame(79800, $summary->capturedAmountByCurrency['INR']);
        $this->assertSame(39900, $summary->averageCapturedByCurrency['INR']);
    }

    public function test_success_rate_is_null_not_zero_with_no_terminal_attempts(): void
    {
        $admin = $this->manager();
        BookingPayment::factory()->create(['status' => 'pending', 'amount_minor' => 49900, 'currency_code' => 'INR']);

        $summary = $this->reports->paymentSummary($admin, $this->period(), $this->filters());

        $this->assertNull($summary->successRate);
    }

    public function test_gross_paid_booking_value_is_currency_grouped_and_never_added_to_collections(): void
    {
        $admin = $this->manager();
        $payment = BookingPayment::factory()->create(['status' => 'captured', 'amount_minor' => 49900, 'currency_code' => 'INR', 'paid_at' => now()]);
        $payment->booking->update(['payment_status' => 'paid', 'price' => '499.00', 'currency' => 'INR']);

        $summary = $this->reports->paymentSummary($admin, $this->period(), $this->filters());

        // Same economic value visible in BOTH metrics, each exactly once —
        // never summed into one number (double-counting prohibition, §7.3).
        $this->assertSame(49900, $summary->capturedAmountByCurrency['INR']);
        $this->assertSame(49900, $summary->grossPaidBookingValueByCurrency['INR']);
        $fields = array_keys(get_object_vars($summary));
        foreach (['revenue', 'totalRevenue', 'netRevenue', 'combinedTotal', 'grandTotal'] as $forbidden) {
            $this->assertNotContains($forbidden, $fields);
        }
    }

    public function test_wallet_debit_is_never_counted_as_external_collection(): void
    {
        $admin = $this->manager();
        $wallet = Wallet::factory()->create();
        $this->ledgerEntry($wallet, WalletLedgerEntryType::BookingPayment, WalletLedgerDirection::Debit, 50000);

        $payments = $this->reports->paymentSummary($admin, $this->period(), $this->filters());

        $this->assertSame([], $payments->capturedAmountByCurrency);
        $this->assertSame(0, $payments->attempts);
    }

    // ── Refunds (§15) ────────────────────────────────────────────────────

    public function test_refund_decisions_and_executed_credits_are_reported_separately(): void
    {
        $admin = $this->manager();
        $payment = BookingPayment::factory()->create(['status' => 'captured', 'amount_minor' => 49900, 'currency_code' => 'INR', 'paid_at' => now()]);
        $booking = $payment->booking;

        $wallet = Wallet::factory()->create(['user_id' => $booking->student_id]);
        $entry = $this->ledgerEntry($wallet, WalletLedgerEntryType::Refund, WalletLedgerDirection::Credit, 49900);

        $lessonId = (string) Str::uuid();
        DB::table('lessons')->insert([
            'id' => $lessonId,
            'booking_id' => $booking->id,
            'student_id' => $booking->student_id,
            'instructor_id' => $booking->instructor_id,
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHours(2),
            'timezone' => 'UTC',
            'status' => 'cancelled',
            'outcome' => 'instructor_no_show',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lesson_financial_dispositions')->insert([
            'id' => (string) Str::uuid(),
            'lesson_id' => $lessonId,
            'booking_id' => $booking->id,
            'outcome' => 'instructor_no_show',
            'student_disposition' => 'full_wallet_refund_required',
            'instructor_disposition' => 'no_earning',
            'processing_status' => 'resolved',
            'refund_ledger_entry_id' => $entry->id,
            'refund_executed_at' => now(),
            'payment_reference' => 'pay_ABC123456789',
            'evaluated_at' => now(),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $refunds = $this->reports->refundSummary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $refunds->refundDecisionsInPeriod);
        $this->assertSame(1, $refunds->executedCount);
        $this->assertSame(49900, $refunds->executedAmountByCurrency['INR']);
        $this->assertSame(0, $refunds->pendingExecution);

        $rows = $this->reports->refundLinkage($admin, $this->period(), $this->filters());
        $row = $rows->items()[0];
        $this->assertSame($booking->reference, $row->bookingReference);
        // Provider reference is masked — never the full identifier.
        $this->assertStringNotContainsString('ABC12345', (string) $row->maskedPaymentReference);
        $this->assertStringContainsString('…', (string) $row->maskedPaymentReference);
    }

    public function test_manual_review_dispositions_are_counted(): void
    {
        $admin = $this->manager();
        $payment = BookingPayment::factory()->create(['status' => 'captured', 'currency_code' => 'INR']);
        $lessonId = (string) Str::uuid();
        DB::table('lessons')->insert([
            'id' => $lessonId, 'booking_id' => $payment->booking->id,
            'student_id' => $payment->booking->student_id, 'instructor_id' => $payment->booking->instructor_id,
            'starts_at' => now()->subHours(3), 'ends_at' => now()->subHours(2), 'timezone' => 'UTC',
            'status' => 'disputed', 'outcome' => 'technical_issue', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lesson_financial_dispositions')->insert([
            'id' => (string) Str::uuid(), 'lesson_id' => $lessonId, 'booking_id' => $payment->booking->id,
            'outcome' => 'technical_issue', 'student_disposition' => 'full_wallet_refund_required',
            'instructor_disposition' => 'compensation_review_required', 'processing_status' => 'manual_review',
            'admin_hold' => true, 'evaluated_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $refunds = $this->reports->refundSummary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $refunds->manualReviewCount);
    }

    // ── Instructor financials (§17/§18) ──────────────────────────────────

    public function test_earning_lifecycle_amounts_by_status_and_currency(): void
    {
        $admin = $this->manager();
        InstructorEarning::factory()->create(['status' => 'releasable', 'earning_amount_minor' => 30000, 'currency_code' => 'INR', 'settlement_batch_id' => null]);
        InstructorEarning::factory()->create(['status' => 'pending_hold', 'earning_amount_minor' => 20000, 'currency_code' => 'INR']);

        $summary = $this->reports->instructorFinancialSummary($admin, $this->period(), $this->filters());

        $this->assertSame(2, $summary->earningsCreatedCount);
        $this->assertSame(50000, $summary->earningsCreatedAmountByCurrency['INR']);
        $this->assertSame(30000, $summary->earningLiabilityByStatusCurrency['releasable']['INR']);
        $this->assertSame(20000, $summary->earningLiabilityByStatusCurrency['pending_hold']['INR']);
        $this->assertSame(30000, $summary->unallocatedReleasableByCurrency['INR']);
    }

    public function test_settlement_allocation_mismatch_is_detected(): void
    {
        $admin = $this->manager();
        $batch = InstructorSettlementBatch::factory()->create(['status' => 'approved', 'total_amount_minor' => 99999, 'currency_code' => 'INR']);
        InstructorEarning::factory()->create(['status' => 'settled', 'earning_amount_minor' => 30000, 'currency_code' => 'INR', 'settlement_batch_id' => $batch->id, 'instructor_id' => $batch->instructor_id]);

        $summary = $this->reports->instructorFinancialSummary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->settlementAllocationMismatchCount);
    }

    public function test_withdrawal_and_payout_stages_are_never_collapsed(): void
    {
        $admin = $this->manager();
        InstructorWithdrawalRequest::factory()->create(['status' => 'approved', 'amount_minor' => 40000, 'net_amount_minor' => 40000, 'currency_code' => 'INR', 'requested_at' => now()]);

        $summary = $this->reports->instructorFinancialSummary($admin, $this->period(), $this->filters());

        $this->assertSame(1, $summary->withdrawalsByStatus['approved'] ?? 0);
        $this->assertSame(40000, $summary->withdrawalRequestedAmountByCurrency['INR']);
        // Approved is NOT paid — no paid amount exists.
        $this->assertArrayNotHasKey('INR', $summary->withdrawalPaidAmountByCurrency);
        $this->assertSame([], $summary->payoutAttemptsByStatus);
    }

    // ── Permission separation (§22) ───────────────────────────────────────

    public function test_wallet_permission_alone_does_not_grant_payment_or_compensation_data(): void
    {
        $walletOnly = User::factory()->create(['status' => 'active']);
        $walletOnly->givePermissionTo(['ViewWalletReports']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($this->reports->canViewWallet($walletOnly));
        $this->assertFalse($this->reports->canViewPayments($walletOnly));
        $this->assertFalse($this->reports->canViewInstructorFinancials($walletOnly));

        $this->expectException(AuthorizationException::class);
        $this->reports->paymentSummary($walletOnly, $this->period(), $this->filters());
    }

    public function test_general_finance_permission_does_not_grant_instructor_compensation(): void
    {
        $financeOnly = User::factory()->create(['status' => 'active']);
        $financeOnly->givePermissionTo(['ViewFinanceReports']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($this->reports->canViewOverview($financeOnly));
        $this->assertFalse($this->reports->canViewInstructorFinancials($financeOnly));

        $this->expectException(AuthorizationException::class);
        $this->reports->instructorFinancialSummary($financeOnly, $this->period(), $this->filters());
    }

    public function test_unauthorized_user_is_denied_everywhere(): void
    {
        $stranger = User::factory()->create(['status' => 'active']);

        $this->assertFalse($this->reports->canViewOverview($stranger));
        $this->assertFalse($this->reports->canViewWallet($stranger));
        $this->assertFalse($this->reports->canViewPayments($stranger));
        $this->assertFalse($this->reports->canViewInstructorFinancials($stranger));
    }

    // ── Zero side effects (§30) ───────────────────────────────────────────

    public function test_report_rendering_has_zero_financial_side_effects(): void
    {
        $admin = $this->manager();
        $wallet = Wallet::factory()->create(['balance_minor' => 10000, 'available_balance_minor' => 10000]);
        $payment = BookingPayment::factory()->create(['status' => 'pending', 'currency_code' => 'INR']);
        $earning = InstructorEarning::factory()->create(['status' => 'releasable', 'earning_amount_minor' => 5000, 'currency_code' => 'INR']);
        $switchesBefore = [
            app(PaymentGatewaySettings::class)->payments_enabled,
            app(InstructorEarningSettings::class)->earnings_enabled,
            app(InstructorEarningSettings::class)->payout_execution_enabled,
            app(InstructorEarningSettings::class)->lesson_refund_execution_enabled,
        ];

        $this->reports->walletSummary($admin, $this->period(), $this->filters());
        $this->reports->refundSummary($admin, $this->period(), $this->filters());
        $this->reports->paymentSummary($admin, $this->period(), $this->filters());
        $this->reports->instructorFinancialSummary($admin, $this->period(), $this->filters());

        $this->assertSame(10000, $wallet->fresh()->balance_minor);
        $this->assertSame('pending', $payment->fresh()->getRawOriginal('status'));
        $this->assertSame('releasable', $earning->fresh()->getRawOriginal('status'));
        $this->assertSame($switchesBefore, [
            app(PaymentGatewaySettings::class)->payments_enabled,
            app(InstructorEarningSettings::class)->earnings_enabled,
            app(InstructorEarningSettings::class)->payout_execution_enabled,
            app(InstructorEarningSettings::class)->lesson_refund_execution_enabled,
        ]);
        Http::assertNothingSent(); // no provider API call, ever
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'reporting')->count()); // no audit writes for ordinary views
    }

    // ── Query performance (§28) ──────────────────────────────────────────

    public function test_summaries_use_bounded_query_counts(): void
    {
        $admin = $this->manager();
        $wallet = Wallet::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->ledgerEntry($wallet, WalletLedgerEntryType::AdminAdjustment, WalletLedgerDirection::Credit, 1000 * $i);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->reports->walletSummary($admin, $this->period(), $this->filters());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Aggregates + settings/permission lookups only (~13 constant) —
        // never proportional to ledger size; an N+1 would add 5 here.
        $this->assertLessThanOrEqual(15, $count);
    }
}
