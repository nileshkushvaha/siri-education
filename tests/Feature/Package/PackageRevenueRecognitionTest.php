<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Models\Payment;
use App\Models\StudentPackagePurchase;
use App\Package\Enums\PackagePurchaseStatus;
use App\Payments\Enums\PaymentStatus;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\PaymentFinancialReportRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4E.3 (PKG-AUD-012) — package sales appear in financial
 * reporting exactly once.
 *
 * Two opposite errors are equally easy to make here, and both are
 * asserted against:
 *
 *   UNDER-count  package revenue is invisible entirely, which is the
 *                bug this closes — packages settle through the generic
 *                `payments` path, so booking collections never saw
 *                them, while their lessons book as PackageFunded, which
 *                booking-value metrics correctly exclude.
 *
 *   OVER-count   the sale is counted per payment ATTEMPT (a declined
 *                card then a successful retry becomes two sales), or
 *                once at purchase AND again as each package-funded
 *                booking's price.
 *
 * The approved Version 1 policy is that package revenue is recognized
 * once, at settlement, and never allocated across the lessons it buys.
 */
class PackageRevenueRecognitionTest extends TestCase
{
    use RefreshDatabase;

    private function period(): ReportingPeriod
    {
        return ReportingPeriod::custom(
            CarbonImmutable::now('UTC')->subDays(30),
            CarbonImmutable::now('UTC'),
            'UTC',
        );
    }

    private function repository(): PaymentFinancialReportRepository
    {
        return app(PaymentFinancialReportRepository::class);
    }

    private function purchase(PackagePurchaseStatus $status, int $amountMinor = 20000, string $currency = 'GBP'): StudentPackagePurchase
    {
        Schema::disableForeignKeyConstraints();

        $purchase = StudentPackagePurchase::query()->create([
            'proposal_id' => (string) Str::uuid(),
            'student_id' => 1,
            'reference' => 'PKG-'.strtoupper(Str::random(12)),
            'amount_minor' => $amountMinor,
            'currency_code' => $currency,
            'status' => $status,
            'accepted_at' => now()->subDay(),
            'paid_at' => $status === PackagePurchaseStatus::Paid ? now()->subHours(2) : null,
        ]);

        Schema::enableForeignKeyConstraints();

        return $purchase;
    }

    private function attempt(StudentPackagePurchase $purchase, PaymentStatus $status): Payment
    {
        Schema::disableForeignKeyConstraints();

        $payment = Payment::query()->create([
            'payable_type' => StudentPackagePurchase::PAYABLE_TYPE,
            'payable_id' => $purchase->id,
            'provider' => 'razorpay',
            'amount_minor' => $purchase->amount_minor,
            'currency_code' => $purchase->currency_code,
            'status' => $status,
            'idempotency_key' => 'PAY-'.strtoupper(Str::random(16)),
            'paid_at' => $status === PaymentStatus::Paid ? now()->subHours(2) : null,
        ]);

        Schema::enableForeignKeyConstraints();

        return $payment;
    }

    // ── 32-34. Counted exactly once ───────────────────────────────────────

    public function test_a_settled_purchase_contributes_its_amount_once(): void
    {
        $this->purchase(PackagePurchaseStatus::Paid, 20000);

        $this->assertSame(
            ['GBP' => 20000],
            $this->repository()->packagePurchaseCollectedByCurrency($this->period(), new ReportFilters($this->period())),
        );
        $this->assertSame(1, $this->repository()->packagePurchasesSold($this->period(), new ReportFilters($this->period())));
    }

    public function test_a_retried_purchase_still_counts_as_one_sale(): void
    {
        $purchase = $this->purchase(PackagePurchaseStatus::Paid, 20000);

        // The realistic history: a declined card, a cancelled attempt,
        // then success. Summing ATTEMPTS would report three sales.
        $this->attempt($purchase, PaymentStatus::Failed);
        $this->attempt($purchase, PaymentStatus::Cancelled);
        $this->attempt($purchase, PaymentStatus::Paid);

        $this->assertSame(
            ['GBP' => 20000],
            $this->repository()->packagePurchaseCollectedByCurrency($this->period(), new ReportFilters($this->period())),
        );
        $this->assertSame(1, $this->repository()->packagePurchasesSold($this->period(), new ReportFilters($this->period())));
    }

    public function test_an_unpaid_purchase_contributes_nothing(): void
    {
        $pending = $this->purchase(PackagePurchaseStatus::PendingPayment, 50000);
        $this->attempt($pending, PaymentStatus::Failed);

        $this->assertSame([], $this->repository()->packagePurchaseCollectedByCurrency($this->period(), new ReportFilters($this->period())));
        $this->assertSame(0, $this->repository()->packagePurchasesSold($this->period(), new ReportFilters($this->period())));
    }

    public function test_currencies_are_reported_separately_and_never_summed(): void
    {
        $this->purchase(PackagePurchaseStatus::Paid, 20000, 'GBP');
        $this->purchase(PackagePurchaseStatus::Paid, 500000, 'INR');

        $collected = $this->repository()->packagePurchaseCollectedByCurrency($this->period(), new ReportFilters($this->period()));

        // No FX conversion exists, and inventing one would be worse than
        // reporting the split.
        $this->assertSame(['GBP' => 20000, 'INR' => 500000], $collected);
    }

    public function test_settlements_outside_the_period_are_excluded(): void
    {
        $old = $this->purchase(PackagePurchaseStatus::Paid, 20000);
        $old->forceFill(['paid_at' => CarbonImmutable::now('UTC')->subDays(90)])->save();

        $this->assertSame([], $this->repository()->packagePurchaseCollectedByCurrency($this->period(), new ReportFilters($this->period())));
    }

    // ── 35 & 41. No double counting, no per-lesson allocation ─────────────

    public function test_package_funded_bookings_add_nothing_to_booking_value(): void
    {
        $this->purchase(PackagePurchaseStatus::Paid, 20000);

        // The booking-value metric counts only bookings whose money came
        // through the booking pipeline. A package-funded booking's value
        // was already collected at purchase, so counting it here would
        // report the same money twice.
        $bookingValue = $this->repository()->grossPaidBookingValueByCurrency($this->period(), new ReportFilters($this->period()));

        $this->assertArrayNotHasKey('GBP', $bookingValue);
    }

    public function test_no_per_lesson_package_revenue_allocation_exists(): void
    {
        // Guards the approved policy structurally: a package is ONE
        // commercial sale. Any `amount / quantity` arithmetic appearing
        // in the reporting domain would be an unapproved accrual policy.
        //
        // Comments are stripped before scanning: the docblock on
        // packagePurchaseCollectedByCurrency() deliberately NAMES these
        // columns to explain why they are not used, and a guard that
        // punished the explanation would push the reasoning out of the
        // code. Only executable references count.
        $source = php_strip_whitespace(
            (new \ReflectionClass(PaymentFinancialReportRepository::class))->getFileName(),
        );

        foreach (['total_quantity', 'paid_quantity', 'bonus_quantity'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'Package revenue must never be allocated per lesson.',
            );
        }
    }

    public function test_the_final_overridden_amount_is_what_is_reported(): void
    {
        // An admin-overridden price is copied onto the purchase at
        // acceptance, so reporting follows the amount actually agreed
        // and charged — never a recalculated list price.
        $this->purchase(PackagePurchaseStatus::Paid, 12345);

        $this->assertSame(
            ['GBP' => 12345],
            $this->repository()->packagePurchaseCollectedByCurrency($this->period(), new ReportFilters($this->period())),
        );
    }
}
