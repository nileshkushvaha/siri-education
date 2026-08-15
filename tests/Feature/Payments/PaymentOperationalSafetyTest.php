<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\DTOs\PaymentStatusResult;
use App\Booking\Enums\BookingPaymentReconciliationIssueType;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Exceptions\GatewayRequestException;
use App\Models\BookingPayment;
use App\Models\BookingPaymentReconciliationIssue;
use App\Models\Payment;
use App\Models\PaymentReconciliationIssue;
use App\Models\StudentPackagePurchase;
use App\Package\Services\PackagePurchaseReconciliationService;
use App\Payments\Enums\PaymentReconciliationIssueStatus;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Payments\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * PAY-1 — the two pre-production payment safety gaps.
 *
 * PAY-AUD-001: a package payment could jam for any reason other than an
 * amount/currency mismatch and appear in no operator queue. The sweep
 * retried silently every five minutes while the student waited.
 *
 * PAY-AUD-005 (as corrected): the booking WEBHOOK path always compared
 * the provider's amount and currency and refused settlement on a
 * mismatch — that part of the audit finding was wrong. What was really
 * missing is that the RECONCILIATION path read the provider's status,
 * discarded the money, and settled; and that a mismatch on either path
 * produced no operator-visible incident.
 */
class PaymentOperationalSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** Older than OPERATOR_VISIBLE_AFTER_MINUTES, so a stuck attempt is genuinely actionable. */
    private function agedMinutes(): int
    {
        return PackagePurchaseReconciliationService::OPERATOR_VISIBLE_AFTER_MINUTES + 5;
    }

    private function packagePayment(array $overrides = []): Payment
    {
        $attributes = [
            'payable_type' => StudentPackagePurchase::PAYABLE_TYPE,
            'payable_id' => (string) Str::uuid(),
            'user_id' => null,
            'provider' => 'stripe',
            'provider_order_id' => 'pi_stuck_'.Str::random(8),
            'amount_minor' => 49900,
            'currency_code' => 'INR',
            'status' => PaymentStatus::Pending,
            'idempotency_key' => 'PKG-'.Str::random(10),
            ...$overrides,
        ];

        $payment = Payment::query()->create($attributes);

        // `initialization_claimed_at` is deliberately NOT fillable — the
        // real claim is an atomic conditional UPDATE, never a mass
        // assignment — so a fixture has to force it, and age the row past
        // every grace window.
        $payment->forceFill([
            'created_at' => now()->subMinutes($this->agedMinutes()),
            'initialization_claimed_at' => $attributes['initialization_claimed_at'] ?? null,
        ])->save();

        return $payment->refresh();
    }

    private function stripeThrows(): void
    {
        $mock = Mockery::mock(StripeGatewayClient::class);
        $mock->shouldReceive('retrievePaymentIntent')->andThrow(new GatewayRequestException('Stripe unreachable.'));
        $this->app->instance(StripeGatewayClient::class, $mock);
    }

    private function stripeReturns(array $intent): void
    {
        $mock = Mockery::mock(StripeGatewayClient::class);
        $mock->shouldReceive('retrievePaymentIntent')->andReturn($intent);
        $this->app->instance(StripeGatewayClient::class, $mock);
    }

    private function reconcile(Payment $payment): void
    {
        app(PackagePurchaseReconciliationService::class)->reconcileOne($payment);
    }

    /** @return Collection<int, PaymentReconciliationIssue> */
    private function issuesFor(Payment $payment)
    {
        return PaymentReconciliationIssue::query()->where('payment_id', $payment->id)->get();
    }

    // ── PAY-AUD-001 · package operational visibility ────────────────────

    public function test_an_unreachable_provider_becomes_an_operator_visible_incident(): void
    {
        $payment = $this->packagePayment();
        $this->stripeThrows();

        $this->reconcile($payment);

        $issue = $this->issuesFor($payment)->sole();
        $this->assertSame(PaymentReconciliationIssueType::ProviderUnavailable, $issue->issue_type);
        $this->assertSame(PaymentReconciliationIssueStatus::Open, $issue->status);
    }

    public function test_a_brand_new_attempt_is_not_an_incident_merely_because_the_provider_blipped(): void
    {
        // Transient unavailability during ordinary checkout latency must
        // not page anyone — the sweep simply retries.
        $payment = $this->packagePayment();
        $payment->forceFill(['created_at' => now()->subMinute()])->save();
        $this->stripeThrows();

        $this->reconcile($payment->refresh());

        $this->assertCount(0, $this->issuesFor($payment));
    }

    public function test_repeated_unavailability_folds_into_one_incident(): void
    {
        // A five-minute scheduler must never mean a five-minute stream of
        // identical rows.
        $payment = $this->packagePayment();
        $this->stripeThrows();

        $this->reconcile($payment);
        $this->reconcile($payment->refresh());
        $this->reconcile($payment->refresh());

        $issue = $this->issuesFor($payment)->sole();
        $this->assertSame(3, $issue->occurrence_count);
        $this->assertSame(PaymentReconciliationIssueStatus::Open, $issue->status);
    }

    public function test_a_long_unresolved_attempt_becomes_visible_as_stale(): void
    {
        $payment = $this->packagePayment();
        // Provider reachable, simply never settles.
        $this->stripeReturns(['id' => $payment->provider_order_id, 'status' => 'requires_payment_method']);

        $this->reconcile($payment);

        $issue = $this->issuesFor($payment)->sole();
        $this->assertSame(PaymentReconciliationIssueType::StaleProcessing, $issue->issue_type);
    }

    public function test_an_attempt_that_never_recorded_a_provider_reference_becomes_visible(): void
    {
        $payment = $this->packagePayment([
            'provider_order_id' => null,
            'initialization_claimed_at' => now()->subMinutes($this->agedMinutes()),
        ]);

        $this->reconcile($payment);

        $issue = $this->issuesFor($payment)->sole();
        $this->assertSame(PaymentReconciliationIssueType::MissingProviderReference, $issue->issue_type);
    }

    public function test_an_attempt_still_awaiting_initialization_is_not_an_incident(): void
    {
        $payment = $this->packagePayment([
            'provider_order_id' => null,
            'initialization_claimed_at' => null,
        ]);

        $this->reconcile($payment);

        $this->assertCount(0, $this->issuesFor($payment));
    }

    public function test_no_incident_ever_settles_a_purchase_or_grants_access(): void
    {
        // The whole point: making a failure visible must never make it
        // look paid. Verified provider evidence remains mandatory.
        $payment = $this->packagePayment();
        $this->stripeThrows();

        $this->reconcile($payment);

        $this->assertNotSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_there_is_no_operator_action_that_marks_a_payment_paid(): void
    {
        // Settlement must stay evidence-driven. An operator can resolve
        // an incident; they cannot declare money collected.
        $resource = (string) file_get_contents(base_path('app/Filament/Resources/PaymentReconciliationIssues/Tables/PaymentReconciliationIssuesTable.php'));

        $this->assertStringNotContainsString('markPaid', $resource);
        $this->assertStringNotContainsString('Mark Paid', $resource);
    }

    // ── PAY-AUD-005 · booking reconciliation verifies the money ─────────

    private function bookingPayment(array $overrides = []): BookingPayment
    {
        return BookingPayment::factory()->create([
            'provider' => 'stripe',
            'provider_order_id' => 'pi_'.Str::random(10),
            'amount_minor' => 4900,
            'currency_code' => 'USD',
            'status' => BookingPaymentRecordStatus::Pending,
            'idempotency_key' => 'PAY-'.strtoupper(Str::random(10)),
            ...$overrides,
        ]);
    }

    public function test_a_reconciled_capture_with_the_wrong_amount_is_refused(): void
    {
        $payment = $this->bookingPayment();

        $result = new PaymentStatusResult(
            recordStatus: BookingPaymentRecordStatus::Captured,
            providerPaymentId: 'py_x',
            providerStatus: 'succeeded',
            safeReason: null,
            verifiedAmountMinor: 100,           // provider took far less
            verifiedCurrency: 'USD',
        );

        $updated = app(BookingPaymentServiceInterface::class)
            ->applyProviderStatus($payment, $result);

        $this->assertSame(BookingPaymentRecordStatus::ResolutionRequired, $updated->status);
        $this->assertNull($updated->paid_at);

        $issue = BookingPaymentReconciliationIssue::query()->where('booking_payment_id', $payment->id)->sole();
        $this->assertSame(BookingPaymentReconciliationIssueType::AmountMismatch, $issue->type);
    }

    public function test_a_reconciled_capture_with_the_wrong_currency_is_refused(): void
    {
        $payment = $this->bookingPayment();

        $result = new PaymentStatusResult(
            recordStatus: BookingPaymentRecordStatus::Captured,
            providerPaymentId: 'py_x',
            providerStatus: 'succeeded',
            safeReason: null,
            verifiedAmountMinor: 4900,
            verifiedCurrency: 'INR',            // right number, wrong money
        );

        $updated = app(BookingPaymentServiceInterface::class)
            ->applyProviderStatus($payment, $result);

        $this->assertSame(BookingPaymentRecordStatus::ResolutionRequired, $updated->status);

        $issue = BookingPaymentReconciliationIssue::query()->where('booking_payment_id', $payment->id)->sole();
        $this->assertSame(BookingPaymentReconciliationIssueType::CurrencyMismatch, $issue->type);
    }

    public function test_a_provider_reporting_no_money_at_all_cannot_settle(): void
    {
        // Silence is not evidence. A status of "succeeded" with nothing
        // to compare cannot prove what was collected.
        $payment = $this->bookingPayment();

        $result = new PaymentStatusResult(
            recordStatus: BookingPaymentRecordStatus::Captured,
            providerPaymentId: 'py_x',
            providerStatus: 'succeeded',
            safeReason: null,
        );

        $updated = app(BookingPaymentServiceInterface::class)
            ->applyProviderStatus($payment, $result);

        $this->assertSame(BookingPaymentRecordStatus::ResolutionRequired, $updated->status);
    }

    public function test_a_matching_capture_still_settles_normally(): void
    {
        $payment = $this->bookingPayment();

        $result = new PaymentStatusResult(
            recordStatus: BookingPaymentRecordStatus::Captured,
            providerPaymentId: 'py_ok',
            providerStatus: 'succeeded',
            safeReason: null,
            verifiedAmountMinor: 4900,
            verifiedCurrency: 'USD',
        );

        $updated = app(BookingPaymentServiceInterface::class)
            ->applyProviderStatus($payment, $result);

        $this->assertSame(BookingPaymentRecordStatus::Captured, $updated->status);
        $this->assertNotNull($updated->paid_at);
        $this->assertCount(0, BookingPaymentReconciliationIssue::query()->where('booking_payment_id', $payment->id)->get());
    }

    public function test_a_mismatch_never_marks_the_booking_financially_settled(): void
    {
        $payment = $this->bookingPayment();

        app(BookingPaymentServiceInterface::class)->applyProviderStatus(
            $payment,
            new PaymentStatusResult(
                recordStatus: BookingPaymentRecordStatus::Captured,
                providerPaymentId: 'py_x',
                providerStatus: 'succeeded',
                safeReason: null,
                verifiedAmountMinor: 1,
                verifiedCurrency: 'USD',
            ),
        );

        $booking = $payment->booking()->first();

        $this->assertNotSame(BookingPaymentStatus::Paid, $booking?->payment_status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_repeated_reconciliation_of_the_same_mismatch_is_idempotent(): void
    {
        $payment = $this->bookingPayment();

        $result = new PaymentStatusResult(
            recordStatus: BookingPaymentRecordStatus::Captured,
            providerPaymentId: 'py_x',
            providerStatus: 'succeeded',
            safeReason: null,
            verifiedAmountMinor: 100,
            verifiedCurrency: 'USD',
        );

        $service = app(BookingPaymentServiceInterface::class);
        $service->applyProviderStatus($payment, $result);
        $service->applyProviderStatus($payment->refresh(), $result);

        // ResolutionRequired is terminal, so the second pass is a no-op
        // rather than a second incident.
        $this->assertCount(1, BookingPaymentReconciliationIssue::query()->where('booking_payment_id', $payment->id)->get());
    }
}
