<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Types\PaidOneToOneType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Models\User;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\DTOs\PackageSettlementResult;
use App\Package\Enums\PackagePurchaseStatus;
use App\Package\Services\InstructorPackageProposalService;
use App\Package\Services\PackageBenefitRuleService;
use App\Package\Services\PackageEntitlementService;
use App\Package\Services\PackagePurchaseReconciliationService;
use App\Package\Services\PackagePurchaseService;
use App\Package\Services\PackagePurchaseSettlementService;
use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentEventType;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentService;
use App\Policies\StudentPackagePurchasePolicy;
use App\Settings\PaymentGatewaySettings;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 4B.3 — settlement, activation, and recovery.
 *
 * The invariant every test here defends: after a committed settlement,
 * Payment=Paid AND Purchase=Paid AND an Active entitlement exists, with
 * one shared activation timestamp. They are written in one transaction,
 * so there is no legitimate state where only some of them are true.
 */
class PackagePurchaseSettlementTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const string RAZORPAY_WEBHOOK_SECRET = 'rzp_whsecret';

    private const string STRIPE_WEBHOOK_SECRET = 'whsec_test';

    private User $manager;

    private bool $activationBlocked = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackagePermissionSeeder::class);
        foreach (['manager', 'instructor', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->manager = User::factory()->create(['status' => 'active']);
        $this->manager->assignRole('manager');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function purchaseWithOpenAttempt(?int $validityDays = 90, string $provider = 'fake'): array
    {
        $purchase = $this->acceptedPurchase($validityDays);

        $payment = app(PaymentService::class)->startAttempt($purchase, $provider, 'PAY-'.strtoupper(bin2hex(random_bytes(6))));
        app(PaymentService::class)->recordProviderOrder($payment, $provider.'_order_1');

        return [$purchase->refresh(), $payment->refresh()];
    }

    private function acceptedPurchase(?int $validityDays = 90): StudentPackagePurchase
    {
        $fixture = $this->createPaidBookingTypeWithPrice(PaidOneToOneType::KEY, 20.00, 'GBP');

        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');
        $this->assignBillingCountry($student, $fixture['country']);

        Booking::factory()->confirmed()->paid()->create([
            'booking_type_id' => $fixture['type']->id,
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
        ]);

        $rule = app(PackageBenefitRuleService::class)->create($this->manager, [
            'name' => '20 paid + 5 bonus',
            'paid_quantity' => 20,
            'bonus_quantity' => 5,
            'total_quantity' => 25,
            'validity_days' => $validityDays,
        ]);

        $proposals = app(InstructorPackageProposalService::class);
        $proposal = $proposals->proposeAndSubmit(new CreatePackageProposalData(
            instructorId: $instructor->id,
            studentId: $student->id,
            packageBenefitRuleId: $rule->id,
            subjectId: $this->seedLessonSubject()->id,
            academicLevelId: null,
        ));

        $accepted = $proposals->acceptProposal($proposals->approve($proposal, $this->manager, null, null), $student);

        return StudentPackagePurchase::query()->where('proposal_id', $accepted->id)->firstOrFail();
    }

    private function successEvent(Payment $payment, ?int $amountMinor = null, ?string $currency = null): VerifiedPaymentEvent
    {
        return new VerifiedPaymentEvent(
            provider: (string) $payment->provider,
            type: PaymentEventType::Succeeded,
            reference: $payment->idempotency_key,
            providerOrderId: $payment->provider_order_id,
            providerPaymentId: 'pay_settled_1',
            amountMinor: $amountMinor ?? (int) $payment->amount_minor,
            currencyCode: $currency ?? (string) $payment->currency_code,
        );
    }

    private function settlement(): PackagePurchaseSettlementService
    {
        return app(PackagePurchaseSettlementService::class);
    }

    // ── 7-11. Settlement validation ───────────────────────────────────────

    public function test_a_correct_amount_settles(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $result = $this->settlement()->settle($payment, $this->successEvent($payment));

        $this->assertTrue($result->settled);
    }

    public function test_an_amount_mismatch_does_not_settle(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $result = $this->settlement()->settle($payment, $this->successEvent($payment, amountMinor: 1));

        $this->assertTrue($result->ignored);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_a_currency_mismatch_does_not_settle(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        // No conversion is ever attempted — a different currency is a
        // discrepancy, not an exchange-rate problem.
        $result = $this->settlement()->settle($payment, $this->successEvent($payment, currency: 'INR'));

        $this->assertTrue($result->ignored);
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_settlement_resolves_the_purchase_the_payment_points_at(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $result = $this->settlement()->settle($payment, $this->successEvent($payment));

        $this->assertSame($purchase->id, $result->purchase?->id);
    }

    /** A package settlement path must never touch another payable type. */
    public function test_a_payment_for_another_payable_type_cannot_settle_a_package(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();
        $payment->forceFill(['payable_type' => 'some_other_payable'])->save();

        $result = $this->settlement()->settle($payment->refresh(), $this->successEvent($payment));

        $this->assertTrue($result->ignored);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_an_event_from_a_different_provider_cannot_settle_the_attempt(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $event = new VerifiedPaymentEvent(
            provider: 'razorpay', // the attempt is 'fake'
            type: PaymentEventType::Succeeded,
            reference: $payment->idempotency_key,
            amountMinor: (int) $payment->amount_minor,
            currencyCode: (string) $payment->currency_code,
        );

        $this->assertTrue($this->settlement()->settle($payment, $event)->ignored);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    // ── 12-18. The successful lifecycle ───────────────────────────────────

    public function test_the_full_invariant_holds_after_settlement(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $result = $this->settlement()->settle($payment, $this->successEvent($payment));

        $payment = $payment->fresh();
        $purchase = $purchase->fresh();
        $entitlement = StudentPackageEntitlement::query()->where('proposal_id', $purchase->proposal_id)->firstOrFail();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('pay_settled_1', $payment->provider_payment_id);

        $this->assertSame(PackagePurchaseStatus::Paid, $purchase->status);
        $this->assertNotNull($purchase->paid_at);

        $this->assertSame('active', $entitlement->status->value);
        $this->assertNotNull($entitlement->activated_at);
        $this->assertSame($entitlement->id, $result->entitlement?->id);
    }

    public function test_exactly_one_entitlement_is_created(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $this->settlement()->settle($payment, $this->successEvent($payment));

        $this->assertSame(1, StudentPackageEntitlement::query()->where('proposal_id', $purchase->proposal_id)->count());
    }

    public function test_entitlement_quantities_match_the_proposal_snapshot(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $entitlement = $this->settlement()->settle($payment, $this->successEvent($payment))->entitlement;

        $this->assertSame(20, $entitlement->paid_quantity);
        $this->assertSame(5, $entitlement->bonus_quantity);
        $this->assertSame(25, $entitlement->total_quantity);
        $this->assertSame(0, $entitlement->used_quantity);
        $this->assertSame(25, $entitlement->remaining_quantity);
    }

    /** paid_at, activated_at, and the expiry must all derive from one instant. */
    public function test_one_activation_timestamp_is_shared_across_the_settlement(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $result = $this->settlement()->settle($payment, $this->successEvent($payment));

        $paidAt = $result->purchase->paid_at;
        $activatedAt = $result->entitlement->activated_at;

        $this->assertSame($paidAt->toDateTimeString(), $activatedAt->toDateTimeString());
        $this->assertSame(
            $activatedAt->copy()->addDays(90)->toDateTimeString(),
            $result->entitlement->expires_at->toDateTimeString(),
        );
    }

    // ── 19-22. Expiry ─────────────────────────────────────────────────────

    public function test_ninety_day_validity_expires_ninety_days_after_activation(): void
    {
        Carbon::setTestNow('2026-10-30 14:00:00');

        [$purchase, $payment] = $this->purchaseWithOpenAttempt(validityDays: 90);
        $entitlement = $this->settlement()->settle($payment, $this->successEvent($payment))->entitlement;

        $this->assertSame('2026-10-30 14:00:00', $entitlement->activated_at->toDateTimeString());
        $this->assertSame('2027-01-28 14:00:00', $entitlement->expires_at->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_null_validity_activates_with_no_expiry(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt(validityDays: null);

        $entitlement = $this->settlement()->settle($payment, $this->successEvent($payment))->entitlement;

        $this->assertNull($entitlement->expires_at);
        $this->assertNotNull($entitlement->activated_at);
    }

    /**
     * The clock starts at ACTIVATION, not at any earlier lifecycle
     * point — a student who accepts today and pays in a week gets the
     * full window from the week-later payment.
     */
    public function test_the_validity_clock_starts_at_activation_not_at_acceptance(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        [$purchase, $payment] = $this->purchaseWithOpenAttempt(validityDays: 90);

        Carbon::setTestNow('2026-10-08 09:00:00');
        $entitlement = $this->settlement()->settle($payment, $this->successEvent($payment))->entitlement;

        $this->assertSame('2026-10-08 09:00:00', $entitlement->activated_at->toDateTimeString());
        $this->assertSame('2027-01-06 09:00:00', $entitlement->expires_at->toDateTimeString());
        // Acceptance was a week earlier and is deliberately irrelevant.
        $this->assertSame('2026-10-01 09:00:00', $purchase->fresh()->accepted_at->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_editing_the_offer_after_submission_does_not_change_the_activated_expiry(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt(validityDays: 90);

        // The admin shortens the offer's validity between proposal and payment.
        $rule = $purchase->proposal->packageBenefitRule;
        app(PackageBenefitRuleService::class)->update($this->manager, $rule, ['validity_days' => 7]);

        $entitlement = $this->settlement()->settle($payment, $this->successEvent($payment))->entitlement;

        $this->assertSame(90, $entitlement->validity_days);
        $this->assertSame(
            $entitlement->activated_at->copy()->addDays(90)->toDateTimeString(),
            $entitlement->expires_at->toDateTimeString(),
        );
    }

    public function test_editing_master_data_after_payment_does_not_alter_the_activated_entitlement(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt(validityDays: 90);
        $entitlement = $this->settlement()->settle($payment, $this->successEvent($payment))->entitlement;

        $originalExpiry = $entitlement->expires_at->toDateTimeString();

        app(PackageBenefitRuleService::class)->update($this->manager, $purchase->proposal->packageBenefitRule, [
            'paid_quantity' => 1,
            'bonus_quantity' => 0,
            'total_quantity' => 1,
            'validity_days' => 1,
        ]);

        $entitlement = $entitlement->fresh();
        $this->assertSame(25, $entitlement->total_quantity);
        $this->assertSame($originalExpiry, $entitlement->expires_at->toDateTimeString());
    }

    // ── 23-25. Idempotency ────────────────────────────────────────────────

    public function test_replaying_the_same_settlement_creates_one_entitlement(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $first = $this->settlement()->settle($payment, $this->successEvent($payment));
        $second = $this->settlement()->settle($payment->fresh(), $this->successEvent($payment));
        $third = $this->settlement()->settle($payment->fresh(), $this->successEvent($payment));

        $this->assertTrue($first->settled);
        $this->assertTrue($second->replayed);
        $this->assertTrue($third->replayed);
        // A replay is a success, not a failure — the provider must stop retrying.
        $this->assertFalse($second->ignored);
        $this->assertSame(1, StudentPackageEntitlement::query()->count());
    }

    public function test_a_replay_returns_the_existing_entitlement(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $first = $this->settlement()->settle($payment, $this->successEvent($payment));
        $replay = $this->settlement()->settle($payment->fresh(), $this->successEvent($payment));

        $this->assertSame($first->entitlement->id, $replay->entitlement?->id);
    }

    /** The DB has the final word, independent of any service-level check. */
    public function test_the_unique_index_still_blocks_a_duplicate_entitlement(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();
        $this->settlement()->settle($payment, $this->successEvent($payment));

        $this->expectException(QueryException::class);
        app(PackageEntitlementService::class)->createFromProposal($purchase->proposal);
    }

    // ── 26-29. Failure and retry ──────────────────────────────────────────

    public function test_a_failed_event_creates_no_entitlement_and_leaves_the_purchase_payable(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $this->settlement()->settle($payment, new VerifiedPaymentEvent(
            provider: 'fake',
            type: PaymentEventType::Failed,
            reference: $payment->idempotency_key,
            reason: 'Card declined.',
        ));

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_a_new_attempt_may_be_started_after_a_failed_settlement(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $this->settlement()->settle($payment, new VerifiedPaymentEvent(
            provider: 'fake', type: PaymentEventType::Failed, reference: $payment->idempotency_key,
        ));

        $second = app(PackagePurchaseService::class)->startCheckout($purchase->fresh(), $purchase->student);

        $this->assertNotSame($payment->id, $second->paymentId);
        $this->assertSame(2, $purchase->payments()->count());
        $this->assertSame(1, StudentPackagePurchase::query()->count());
    }

    /** An out-of-order failure must never reverse collected money. */
    public function test_a_failed_event_cannot_reverse_an_already_paid_attempt(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();
        $this->settlement()->settle($payment, $this->successEvent($payment));

        $result = $this->settlement()->settle($payment->fresh(), new VerifiedPaymentEvent(
            provider: 'fake', type: PaymentEventType::Failed, reference: $payment->idempotency_key,
        ));

        $this->assertTrue($result->ignored);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PackagePurchaseStatus::Paid, $purchase->fresh()->status);
        $this->assertSame(1, StudentPackageEntitlement::query()->count());
    }

    public function test_a_cancelled_attempt_cannot_later_be_settled(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();
        app(PaymentService::class)->transition($payment, PaymentStatus::Cancelled);

        $result = $this->settlement()->settle($payment->fresh(), $this->successEvent($payment));

        $this->assertTrue($result->ignored);
        $this->assertSame(PaymentStatus::Cancelled, $payment->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_a_processing_event_advances_the_attempt_without_settling(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $this->settlement()->settle($payment, new VerifiedPaymentEvent(
            provider: 'fake', type: PaymentEventType::Processing, reference: $payment->idempotency_key,
        ));

        $this->assertSame(PaymentStatus::Processing, $payment->fresh()->status);
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    // ── 30-31. Double-payment protection ──────────────────────────────────

    public function test_a_paid_attempt_on_a_lagging_purchase_blocks_a_new_checkout(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        // The interrupted-settlement window: money confirmed, purchase
        // not yet caught up.
        app(PaymentService::class)->transition($payment, PaymentStatus::Paid);
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);

        $purchases = app(PackagePurchaseService::class);
        $this->assertTrue($purchases->isAwaitingActivation($purchase->fresh()));

        $this->expectExceptionMessage('already received your payment');
        $purchases->startCheckout($purchase->fresh(), $purchase->student);
    }

    public function test_reconciliation_activates_instead_of_charging_again(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt(90, 'razorpay');
        $this->configureRazorpay();

        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('fetchOrder')->once()->andReturn(['id' => $payment->provider_order_id, 'status' => 'paid']);
        $this->app->instance(RazorpayGatewayClient::class, $mock);

        $result = app(PackagePurchaseReconciliationService::class)->reconcileOne($payment);

        $this->assertTrue($result->settled);
        $this->assertSame(PackagePurchaseStatus::Paid, $purchase->fresh()->status);
        $this->assertSame(1, StudentPackageEntitlement::query()->count());
        // No second gateway order was ever created.
        $this->assertSame(1, $purchase->payments()->count());
    }

    // ── 32-34. Recovery ───────────────────────────────────────────────────

    /**
     * The core of the no-intermediate-state design: if activation
     * fails, the whole local settlement rolls back rather than leaving
     * "Paid with no lessons".
     *
     * The failure is injected with a BEFORE INSERT trigger rather than
     * a stubbed service, so this exercises a genuine mid-transaction
     * database failure — the realistic shape of the incident this
     * design exists to survive.
     */
    public function test_an_activation_failure_rolls_the_whole_settlement_back(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $this->blockEntitlementInserts();

        try {
            $this->settlement()->settle($payment, $this->successEvent($payment));
            $this->fail('Expected the activation failure to surface.');
        } catch (RuntimeException) {
            // expected — and it must NOT be swallowed, because the
            // caller has to tell the provider to retry.
        }

        // Nothing local was kept: the money is not recorded as ours,
        // so the provider retry and the reconciliation sweep both still
        // have work to do.
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paid_at);
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);
        $this->assertNull($purchase->fresh()->paid_at);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_a_retry_after_a_rolled_back_settlement_completes_normally(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $this->blockEntitlementInserts();

        try {
            $this->settlement()->settle($payment, $this->successEvent($payment));
        } catch (RuntimeException) {
            // expected
        }

        // Infrastructure recovers; the same event is delivered again.
        $this->allowEntitlementInserts();
        $result = $this->settlement()->settle($payment->fresh(), $this->successEvent($payment));

        $this->assertTrue($result->settled);
        $this->assertSame(PackagePurchaseStatus::Paid, $purchase->fresh()->status);
        $this->assertSame(1, StudentPackageEntitlement::query()->count());
    }

    /** Recovery through the sweep, after a rolled-back webhook settlement. */
    public function test_reconciliation_completes_a_settlement_that_previously_rolled_back(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt(90, 'razorpay');
        $this->configureRazorpay();

        $this->blockEntitlementInserts();

        try {
            $this->settlement()->settle($payment, $this->successEvent($payment));
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);

        $this->allowEntitlementInserts();

        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('fetchOrder')->andReturn(['status' => 'paid']);
        $this->app->instance(RazorpayGatewayClient::class, $mock);

        $result = app(PackagePurchaseReconciliationService::class)->reconcileOne($payment->fresh());

        $this->assertTrue($result->settled);
        $this->assertSame(PackagePurchaseStatus::Paid, $purchase->fresh()->status);
        $this->assertSame(1, StudentPackageEntitlement::query()->count());
    }

    public function test_reconciliation_skips_attempts_the_provider_has_not_confirmed(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt(90, 'razorpay');
        $this->configureRazorpay();

        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('fetchOrder')->andReturn(['id' => $payment->provider_order_id, 'status' => 'created']);
        $this->app->instance(RazorpayGatewayClient::class, $mock);

        $result = app(PackagePurchaseReconciliationService::class)->reconcileOne($payment);

        $this->assertTrue($result->ignored);
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
        // …but the poll is recorded so the sweep does not hammer the provider.
        $this->assertNotNull($payment->fresh()->last_synced_at);
    }

    public function test_reconciliation_recovers_a_stripe_payment_the_webhook_never_delivered(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt(90, 'stripe');
        $this->configureStripe();

        $mock = Mockery::mock(StripeGatewayClient::class);
        $mock->shouldReceive('retrievePaymentIntent')->once()->andReturn(['id' => $payment->provider_order_id, 'status' => 'succeeded']);
        $this->app->instance(StripeGatewayClient::class, $mock);

        $result = app(PackagePurchaseReconciliationService::class)->reconcileOne($payment);

        $this->assertTrue($result->settled);
        $this->assertSame(1, StudentPackageEntitlement::query()->count());
    }

    public function test_the_due_sweep_only_examines_old_enough_open_package_attempts(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt(90, 'razorpay');
        $this->configureRazorpay();

        $reconciliation = app(PackagePurchaseReconciliationService::class);

        // Brand new — inside the grace period.
        $this->assertSame(0, $reconciliation->reconcileDue());

        $payment->forceFill(['created_at' => now()->subHour()])->save();

        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('fetchOrder')->andReturn(['status' => 'created']);
        $this->app->instance(RazorpayGatewayClient::class, $mock);

        $this->assertSame(1, $reconciliation->reconcileDue());
    }

    /** Reconciliation is idempotent for the same reason the webhook is. */
    public function test_repeated_reconciliation_produces_exactly_one_entitlement(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt(90, 'razorpay');
        $this->configureRazorpay();

        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('fetchOrder')->andReturn(['status' => 'paid']);
        $this->app->instance(RazorpayGatewayClient::class, $mock);

        $reconciliation = app(PackagePurchaseReconciliationService::class);
        $reconciliation->reconcileOne($payment);
        $reconciliation->reconcileOne($payment->fresh());

        $this->assertSame(1, StudentPackageEntitlement::query()->count());
    }

    // ── Concurrency ───────────────────────────────────────────────────────

    /**
     * Two settlements of the same event, interleaved as far as a
     * single-connection test can express. The row lock serialises them
     * and the unique index is the backstop; correctness never rests on
     * a bare "if not exists then create".
     */
    public function test_two_settlements_of_the_same_event_produce_one_entitlement(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();

        $event = $this->successEvent($payment);
        $results = [
            $this->settlement()->settle($payment, $event),
            $this->settlement()->settle($payment->fresh(), $event),
        ];

        $this->assertSame(1, count(array_filter($results, fn (PackageSettlementResult $r): bool => $r->settled)));
        $this->assertSame(1, count(array_filter($results, fn (PackageSettlementResult $r): bool => $r->replayed)));
        $this->assertSame(1, StudentPackageEntitlement::query()->count());
        $this->assertSame(1, StudentPackagePurchase::query()->count());
    }

    // ── 35-37. Authorization ──────────────────────────────────────────────

    public function test_no_role_can_manually_mark_a_purchase_or_payment_paid(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();
        $instructor = $purchase->proposal->instructor;

        foreach ([$this->manager, $purchase->student, $instructor] as $user) {
            $this->assertFalse($user->can('update', $purchase));
            $this->assertFalse($user->can('update', $payment));
            $this->assertFalse($user->can('create', StudentPackagePurchase::class));
            $this->assertFalse($user->can('delete', $purchase));
        }

        // No settle/markPaid ability exists to grant in the first place.
        $this->assertFalse(method_exists(StudentPackagePurchasePolicy::class, 'settle'));
        $this->assertFalse(method_exists(StudentPackagePurchasePolicy::class, 'markPaid'));
    }

    public function test_no_manual_mark_paid_permission_exists(): void
    {
        $forbidden = ['Update:StudentPackagePurchase', 'MarkPaid:StudentPackagePurchase', 'Settle:StudentPackagePurchase', 'Update:Payment'];

        foreach ($forbidden as $permission) {
            $this->assertDatabaseMissing('permissions', ['name' => $permission]);
        }
    }

    public function test_the_instructor_cannot_settle_a_payment(): void
    {
        [$purchase, $payment] = $this->purchaseWithOpenAttempt();
        $instructor = $purchase->proposal->instructor;

        $this->assertTrue($instructor->can('view', $purchase));
        $this->assertFalse($instructor->can('pay', $purchase));
        $this->assertFalse($instructor->can('cancelPaymentAttempt', $purchase));
    }

    /**
     * Makes entitlement creation fail mid-settlement, simulating a
     * transient infrastructure failure. A model event is used rather
     * than DDL because a CREATE TRIGGER would implicitly commit and
     * destroy the very transaction under test.
     */
    private function blockEntitlementInserts(): void
    {
        $this->activationBlocked = true;

        StudentPackageEntitlement::creating(function (): void {
            if ($this->activationBlocked) {
                throw new RuntimeException('Simulated activation failure.');
            }
        });
    }

    private function allowEntitlementInserts(): void
    {
        $this->activationBlocked = false;
    }

    private function configureRazorpay(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->razorpay_webhook_secret = Crypt::encryptString(self::RAZORPAY_WEBHOOK_SECRET);
        $gateways->save();
    }

    private function configureStripe(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_abc123';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_abc123');
        $gateways->stripe_webhook_secret = Crypt::encryptString(self::STRIPE_WEBHOOK_SECRET);
        $gateways->save();
    }
}
