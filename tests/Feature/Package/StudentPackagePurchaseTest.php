<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Exceptions\GatewayRequestException;
use App\Booking\Types\PaidOneToOneType;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Exceptions\ImmutableRecordCannotBeUpdatedException;
use App\Models\Booking;
use App\Models\InstructorPackageProposal;
use App\Models\Payment;
use App\Models\StudentLessonPrice;
use App\Models\StudentPackagePurchase;
use App\Models\User;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\Enums\PackagePurchaseStatus;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use App\Package\Services\PackageBenefitRuleService;
use App\Package\Services\PackagePurchaseService;
use App\Payments\Contracts\Payable;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentService;
use App\Settings\PaymentGatewaySettings;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 4B.2 — StudentPackagePurchase and package checkout.
 *
 * The three properties these tests exist to protect:
 *   1. acceptance produces a purchase and NO usable lessons;
 *   2. the purchase is a price snapshot, immune to later pricing changes;
 *   3. a failed payment produces a new ATTEMPT, never a new purchase.
 */
class StudentPackagePurchaseTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackagePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->manager = User::factory()->create(['status' => 'active']);
        $this->manager->assignRole('manager');
    }

    private function proposals(): InstructorPackageProposalService
    {
        return app(InstructorPackageProposalService::class);
    }

    private function purchases(): PackagePurchaseService
    {
        return app(PackagePurchaseService::class);
    }

    /** A Submitted proposal from a genuinely related instructor/student pair. */
    private function submittedProposal(string $currency = 'GBP', float $unitPrice = 20.00): InstructorPackageProposal
    {
        $fixture = $this->createPaidBookingTypeWithPrice(PaidOneToOneType::KEY, $unitPrice, $currency);

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
            'validity_days' => 90,
        ]);

        return $this->proposals()->proposeAndSubmit(new CreatePackageProposalData(
            instructorId: $instructor->id,
            studentId: $student->id,
            packageBenefitRuleId: $rule->id,
            subjectId: $this->seedLessonSubject()->id,
            academicLevelId: null,
        ));
    }

    private function approvedProposal(?int $overrideMinor = null, string $currency = 'GBP'): InstructorPackageProposal
    {
        return $this->proposals()->approve(
            $this->submittedProposal($currency),
            $this->manager,
            $overrideMinor,
            $overrideMinor === null ? null : 'Agreed discount.',
        );
    }

    private function acceptedPurchase(?int $overrideMinor = null, string $currency = 'GBP'): StudentPackagePurchase
    {
        $proposal = $this->approvedProposal($overrideMinor, $currency);
        $accepted = $this->proposals()->acceptProposal($proposal, $proposal->student);

        return StudentPackagePurchase::query()->where('proposal_id', $accepted->id)->firstOrFail();
    }

    // ── 1-10. Purchase creation ───────────────────────────────────────────

    public function test_accepting_an_approved_proposal_creates_exactly_one_purchase(): void
    {
        $proposal = $this->approvedProposal();

        $this->proposals()->acceptProposal($proposal, $proposal->student);

        $this->assertSame(1, StudentPackagePurchase::query()->where('proposal_id', $proposal->id)->count());
    }

    public function test_accepting_transitions_the_proposal_to_accepted(): void
    {
        $proposal = $this->approvedProposal();

        $accepted = $this->proposals()->acceptProposal($proposal, $proposal->student);

        $this->assertSame('accepted', $accepted->status->value);
        $this->assertNotNull($accepted->accepted_at);
    }

    /** The single most important behavioural change of this phase. */
    public function test_accepting_creates_no_entitlement(): void
    {
        $proposal = $this->approvedProposal();

        $this->proposals()->acceptProposal($proposal, $proposal->student);

        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_purchase_amount_equals_the_proposals_final_price(): void
    {
        // An admin override is the sharpest version of this: the
        // purchase must follow the FINAL price, not the calculated one.
        $proposal = $this->approvedProposal(overrideMinor: 27500);
        $this->assertSame(40000, $proposal->calculated_price_minor);
        $this->assertSame(27500, $proposal->final_price_minor);

        $this->proposals()->acceptProposal($proposal, $proposal->student);
        $purchase = StudentPackagePurchase::query()->where('proposal_id', $proposal->id)->firstOrFail();

        $this->assertSame(27500, $purchase->amount_minor);
    }

    public function test_purchase_currency_matches_the_proposal_snapshot(): void
    {
        $purchase = $this->acceptedPurchase();

        $this->assertSame('GBP', $purchase->currency_code);
        $this->assertSame($purchase->proposal->currency_id, $purchase->currency_id);
    }

    public function test_changing_pricing_afterwards_does_not_change_the_purchase(): void
    {
        $purchase = $this->acceptedPurchase();
        $originalAmount = $purchase->amount_minor;

        // Every active price in the system doubles after acceptance.
        StudentLessonPrice::query()->update(['amount_minor' => 9999]);

        $this->assertSame($originalAmount, $purchase->fresh()->amount_minor);
    }

    public function test_accepting_twice_cannot_create_a_second_purchase(): void
    {
        $proposal = $this->approvedProposal();
        $accepted = $this->proposals()->acceptProposal($proposal, $proposal->student);

        try {
            $this->proposals()->acceptProposal($accepted, $accepted->student);
            $this->fail('Expected a second acceptance to be rejected.');
        } catch (PackageException) {
            // expected — Accepted is terminal, so the transition guard fires.
        }

        $this->assertSame(1, StudentPackagePurchase::query()->where('proposal_id', $proposal->id)->count());
    }

    /** The DB is the real guard, independent of the service's status check. */
    public function test_a_second_purchase_for_the_same_proposal_is_rejected_at_database_level(): void
    {
        $purchase = $this->acceptedPurchase();

        $this->expectException(QueryException::class);
        StudentPackagePurchase::query()->create([
            'proposal_id' => $purchase->proposal_id,
            'student_id' => $purchase->student_id,
            'reference' => 'PKG-DUPLICATE01',
            'amount_minor' => 100,
            'currency_code' => 'GBP',
        ]);
    }

    public function test_an_unrelated_student_cannot_accept_and_create_a_purchase(): void
    {
        $proposal = $this->approvedProposal();
        $other = User::factory()->create(['status' => 'active']);
        $other->assignRole('student');

        try {
            $this->proposals()->acceptProposal($proposal, $other);
            $this->fail('Expected acceptance by an unrelated student to be rejected.');
        } catch (PackageException) {
            // expected
        }

        $this->assertDatabaseCount('student_package_purchases', 0);
    }

    public function test_the_instructor_cannot_accept_and_create_a_purchase_for_the_student(): void
    {
        $proposal = $this->approvedProposal();

        $this->assertFalse($proposal->instructor->can('accept', $proposal));

        try {
            $this->proposals()->acceptProposal($proposal, $proposal->instructor);
            $this->fail('Expected acceptance by the instructor to be rejected.');
        } catch (PackageException) {
            // expected
        }

        $this->assertDatabaseCount('student_package_purchases', 0);
    }

    public function test_a_declined_proposal_creates_no_purchase(): void
    {
        $proposal = $this->approvedProposal();

        $this->proposals()->declineProposal($proposal, $proposal->student);

        $this->assertDatabaseCount('student_package_purchases', 0);
    }

    // ── 11-15. Payable ────────────────────────────────────────────────────

    public function test_a_purchase_is_a_payable(): void
    {
        $this->assertInstanceOf(Payable::class, $this->acceptedPurchase());
    }

    public function test_payable_type_is_the_stable_package_purchase_alias(): void
    {
        $purchase = $this->acceptedPurchase();

        $this->assertSame('package_purchase', $purchase->paymentPayableType());
        $this->assertStringNotContainsString('\\', $purchase->paymentPayableType());
    }

    public function test_the_payment_morph_relation_round_trips_back_to_the_purchase(): void
    {
        $purchase = $this->acceptedPurchase();
        $payment = app(PaymentService::class)->startAttempt($purchase, 'fake');

        $resolved = Payment::query()->whereKey($payment->id)->firstOrFail()->payable;

        $this->assertInstanceOf(StudentPackagePurchase::class, $resolved);
        $this->assertSame($purchase->id, $resolved->id);
        // …and from the other direction.
        $this->assertTrue($purchase->payments()->whereKey($payment->id)->exists());
    }

    public function test_the_database_stores_the_alias_not_a_fqcn(): void
    {
        $purchase = $this->acceptedPurchase();
        app(PaymentService::class)->startAttempt($purchase, 'fake');

        $this->assertDatabaseHas('payments', [
            'payable_type' => 'package_purchase',
            'payable_id' => $purchase->id,
        ]);
        $this->assertDatabaseMissing('payments', ['payable_type' => StudentPackagePurchase::class]);
    }

    public function test_payable_values_come_from_the_purchase_snapshot(): void
    {
        $purchase = $this->acceptedPurchase(overrideMinor: 27500);

        $this->assertSame(27500, $purchase->paymentAmountMinor());
        $this->assertSame('GBP', $purchase->paymentCurrencyCode());
        $this->assertSame((int) $purchase->student_id, $purchase->paymentUserId());
        $this->assertSame($purchase->reference, $purchase->paymentReference());
        $this->assertStringStartsWith('PKG-', $purchase->paymentReference());
    }

    // ── 16-21. Starting a payment attempt ─────────────────────────────────

    public function test_the_owner_can_start_a_payment_attempt_for_a_pending_purchase(): void
    {
        $purchase = $this->acceptedPurchase();

        $checkout = $this->purchases()->startCheckout($purchase, $purchase->student);

        $this->assertFalse($checkout->resumed);
        $this->assertSame($purchase->amount_minor, $checkout->amountMinor);
        $this->assertSame('GBP', $checkout->currencyCode);
        $this->assertSame(PaymentStatus::Pending, Payment::query()->findOrFail($checkout->paymentId)->status);
    }

    public function test_another_student_cannot_start_a_payment_for_someone_elses_purchase(): void
    {
        $purchase = $this->acceptedPurchase();
        $other = User::factory()->create(['status' => 'active']);
        $other->assignRole('student');

        $this->assertFalse($other->can('pay', $purchase));

        // Even bypassing the policy, the service refuses.
        $this->expectException(PackageException::class);
        $this->purchases()->startCheckout($purchase, $other);
    }

    /**
     * There is no request-supplied amount anywhere in the flow: the
     * checkout entry point takes a purchase and an actor, nothing more.
     */
    public function test_the_amount_cannot_be_supplied_by_the_caller(): void
    {
        $parameters = array_map(
            fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(PackagePurchaseService::class, 'startCheckout'))->getParameters(),
        );

        $this->assertSame(['purchase', 'student'], $parameters);

        $purchase = $this->acceptedPurchase();
        $checkout = $this->purchases()->startCheckout($purchase, $purchase->student);

        $this->assertSame($purchase->amount_minor, Payment::query()->findOrFail($checkout->paymentId)->amount_minor);
    }

    public function test_the_currency_cannot_be_supplied_by_the_caller(): void
    {
        $purchase = $this->acceptedPurchase();
        $checkout = $this->purchases()->startCheckout($purchase, $purchase->student);

        $this->assertSame($purchase->currency_code, Payment::query()->findOrFail($checkout->paymentId)->currency_code);
        $this->assertSame($purchase->currency_code, $checkout->checkoutPayload['currency']);
    }

    public function test_checkout_fails_when_payments_are_disabled_platform_wide(): void
    {
        $purchase = $this->acceptedPurchase();

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payments_enabled = false;
        $gateways->save();

        $this->expectException(PackageException::class);
        $this->purchases()->startCheckout($purchase, $purchase->student);
    }

    public function test_checkout_fails_when_the_resolved_provider_cannot_take_the_currency(): void
    {
        // Razorpay is INR-only; this purchase is in GBP.
        $purchase = $this->acceptedPurchase();
        $this->configureRazorpay();

        $this->expectExceptionMessage('No payment method is available');
        $this->purchases()->startCheckout($purchase, $purchase->student);
    }

    public function test_the_attempt_points_at_the_correct_purchase(): void
    {
        $purchase = $this->acceptedPurchase();
        $checkout = $this->purchases()->startCheckout($purchase, $purchase->student);

        $payment = Payment::query()->findOrFail($checkout->paymentId);

        $this->assertSame('package_purchase', $payment->payable_type);
        $this->assertSame($purchase->id, $payment->payable_id);
        $this->assertSame((int) $purchase->student_id, (int) $payment->user_id);
    }

    // ── 22-28. Retry, resume, cancel ──────────────────────────────────────

    public function test_a_failed_attempt_is_kept_and_a_retry_creates_a_second_attempt(): void
    {
        $purchase = $this->acceptedPurchase();

        $first = Payment::query()->findOrFail($this->purchases()->startCheckout($purchase, $purchase->student)->paymentId);
        app(PaymentService::class)->transition($first, PaymentStatus::Failed, ['failure_code' => 'card_declined']);

        $second = Payment::query()->findOrFail($this->purchases()->startCheckout($purchase, $purchase->student)->paymentId);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(PaymentStatus::Failed, $first->fresh()->status);
        $this->assertSame('card_declined', $first->fresh()->failure_code);
        $this->assertSame(2, $purchase->payments()->count());
    }

    public function test_a_retry_never_creates_a_second_purchase(): void
    {
        $purchase = $this->acceptedPurchase();

        $first = Payment::query()->findOrFail($this->purchases()->startCheckout($purchase, $purchase->student)->paymentId);
        app(PaymentService::class)->transition($first, PaymentStatus::Failed);
        $this->purchases()->startCheckout($purchase, $purchase->student);

        $this->assertSame(1, StudentPackagePurchase::query()->count());
        $this->assertSame(1, StudentPackagePurchase::query()->where('proposal_id', $purchase->proposal_id)->count());
    }

    /** A double-clicked Pay button resumes rather than opening a second gateway order. */
    public function test_clicking_pay_again_resumes_the_open_attempt_instead_of_duplicating_it(): void
    {
        $purchase = $this->acceptedPurchase();

        $first = $this->purchases()->startCheckout($purchase, $purchase->student);
        $second = $this->purchases()->startCheckout($purchase, $purchase->student);

        $this->assertSame($first->paymentId, $second->paymentId);
        $this->assertFalse($first->resumed);
        $this->assertTrue($second->resumed);
        $this->assertSame(1, $purchase->payments()->count());
    }

    public function test_a_cancelled_attempt_allows_a_brand_new_attempt(): void
    {
        $purchase = $this->acceptedPurchase();

        $first = $this->purchases()->startCheckout($purchase, $purchase->student);
        $cancelled = $this->purchases()->cancelOpenAttempt($purchase, $purchase->student);
        $this->assertSame(PaymentStatus::Cancelled, $cancelled->status);

        $second = $this->purchases()->startCheckout($purchase, $purchase->student);

        $this->assertNotSame($first->paymentId, $second->paymentId);
        $this->assertFalse($second->resumed);
        $this->assertSame(2, $purchase->payments()->count());
    }

    public function test_cancelling_with_nothing_open_is_rejected(): void
    {
        $purchase = $this->acceptedPurchase();

        $this->expectException(PackageException::class);
        $this->purchases()->cancelOpenAttempt($purchase, $purchase->student);
    }

    public function test_another_student_cannot_cancel_someone_elses_attempt(): void
    {
        $purchase = $this->acceptedPurchase();
        $this->purchases()->startCheckout($purchase, $purchase->student);

        $other = User::factory()->create(['status' => 'active']);
        $other->assignRole('student');

        $this->assertFalse($other->can('cancelPaymentAttempt', $purchase));

        $this->expectException(PackageException::class);
        $this->purchases()->cancelOpenAttempt($purchase, $other);
    }

    public function test_the_purchase_stays_pending_payment_after_failed_and_cancelled_attempts(): void
    {
        $purchase = $this->acceptedPurchase();

        $first = Payment::query()->findOrFail($this->purchases()->startCheckout($purchase, $purchase->student)->paymentId);
        app(PaymentService::class)->transition($first, PaymentStatus::Failed);

        $this->purchases()->startCheckout($purchase, $purchase->student);
        $this->purchases()->cancelOpenAttempt($purchase, $purchase->student);

        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);
        $this->assertNull($purchase->fresh()->paid_at);
    }

    // ── Phase boundary: nothing settles here ──────────────────────────────

    public function test_checkout_never_marks_the_purchase_paid(): void
    {
        $purchase = $this->acceptedPurchase();

        $this->purchases()->startCheckout($purchase, $purchase->student);

        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);
        $this->assertDatabaseMissing('payments', ['payable_id' => $purchase->id, 'status' => 'paid']);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    // ── Gateway reuse ─────────────────────────────────────────────────────

    public function test_razorpay_checkout_goes_through_the_shared_gateway_client(): void
    {
        $purchase = $this->acceptedPurchase(currency: 'INR');
        $this->configureRazorpay();

        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('createOrder')->once()->andReturn(['id' => 'order_PKG1']);
        $this->app->instance(RazorpayGatewayClient::class, $mock);

        $checkout = $this->purchases()->startCheckout($purchase, $purchase->student);

        $this->assertSame('razorpay', $checkout->provider);
        $this->assertSame('order_PKG1', $checkout->checkoutPayload['order_id']);
        $this->assertSame('order_PKG1', Payment::query()->findOrFail($checkout->paymentId)->provider_order_id);
    }

    public function test_a_gateway_failure_leaves_a_failed_attempt_rather_than_an_open_one(): void
    {
        $purchase = $this->acceptedPurchase(currency: 'INR');
        $this->configureRazorpay();

        $mock = Mockery::mock(RazorpayGatewayClient::class);
        $mock->shouldReceive('createOrder')->andThrow(new GatewayRequestException('gateway down'));
        $this->app->instance(RazorpayGatewayClient::class, $mock);

        try {
            $this->purchases()->startCheckout($purchase, $purchase->student);
            $this->fail('Expected a gateway failure to surface.');
        } catch (PackageException) {
            // expected
        }

        $attempt = $purchase->payments()->firstOrFail();
        $this->assertSame(PaymentStatus::Failed, $attempt->status);
        $this->assertSame('provider_order_failed', $attempt->failure_code);
        // …and the student is not blocked from trying again.
        $this->assertNull(app(PackagePurchaseService::class)->openAttemptFor($purchase));
    }

    /** Stripe's client_secret is never stored, so resuming re-fetches the SAME intent. */
    public function test_resuming_a_stripe_attempt_refetches_the_intent_without_creating_a_second_one(): void
    {
        $purchase = $this->acceptedPurchase();
        $this->configureStripe();
        app(PaymentGatewaySettings::class)->fill(['default_provider' => 'stripe'])->save();

        $mock = Mockery::mock(StripeGatewayClient::class);
        $mock->shouldReceive('createPaymentIntent')->once()->andReturn(['id' => 'pi_PKG1', 'client_secret' => 'secret_1']);
        $mock->shouldReceive('retrievePaymentIntent')->once()->andReturn(['id' => 'pi_PKG1', 'client_secret' => 'secret_1']);
        $this->app->instance(StripeGatewayClient::class, $mock);

        $first = $this->purchases()->startCheckout($purchase, $purchase->student);
        $resumed = $this->purchases()->startCheckout($purchase, $purchase->student);

        $this->assertSame('pi_PKG1', $resumed->checkoutPayload['payment_intent_id']);
        $this->assertSame('secret_1', $resumed->checkoutPayload['client_secret']);
        $this->assertSame($first->paymentId, $resumed->paymentId);
        $this->assertSame(1, $purchase->payments()->count());
    }

    /** No secret ever reaches the frontend payload or the payments table. */
    public function test_no_gateway_secret_is_returned_or_persisted(): void
    {
        $purchase = $this->acceptedPurchase();
        $checkout = $this->purchases()->startCheckout($purchase, $purchase->student);

        $this->assertArrayNotHasKey('key_secret', $checkout->checkoutPayload);
        $this->assertArrayNotHasKey('secret_key', $checkout->checkoutPayload);
        $this->assertArrayNotHasKey('client_secret', Payment::query()->findOrFail($checkout->paymentId)->getAttributes());
    }

    // ── Immutability & authorization ──────────────────────────────────────

    public function test_the_commercial_snapshot_cannot_be_edited(): void
    {
        $purchase = $this->acceptedPurchase();

        $this->expectException(ImmutableRecordCannotBeUpdatedException::class);
        $purchase->forceFill(['amount_minor' => 1])->save();
    }

    public function test_a_purchase_cannot_be_hard_deleted(): void
    {
        $purchase = $this->acceptedPurchase();

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $purchase->delete();
    }

    public function test_the_authorization_matrix(): void
    {
        $purchase = $this->acceptedPurchase();
        $instructor = $purchase->proposal->instructor;

        // Student: sees and pays for their own.
        $this->assertTrue($purchase->student->can('view', $purchase));
        $this->assertTrue($purchase->student->can('pay', $purchase));

        // Instructor: read-only visibility, never payment control.
        $this->assertTrue($instructor->can('view', $purchase));
        $this->assertFalse($instructor->can('pay', $purchase));
        $this->assertFalse($instructor->can('cancelPaymentAttempt', $purchase));

        // Admin: listing only, and may not pay on anyone's behalf.
        $this->assertTrue($this->manager->can('viewAny', StudentPackagePurchase::class));
        $this->assertTrue($this->manager->can('view', $purchase));
        $this->assertFalse($this->manager->can('pay', $purchase));

        // Nobody may create, edit, or delete a financial record.
        foreach ([$this->manager, $purchase->student, $instructor] as $user) {
            $this->assertFalse($user->can('create', StudentPackagePurchase::class));
            $this->assertFalse($user->can('update', $purchase));
            $this->assertFalse($user->can('delete', $purchase));
        }
    }

    public function test_no_role_may_edit_a_payment_record(): void
    {
        $purchase = $this->acceptedPurchase();
        $payment = Payment::query()->findOrFail($this->purchases()->startCheckout($purchase, $purchase->student)->paymentId);

        foreach ([$this->manager, $purchase->student, $purchase->proposal->instructor] as $user) {
            $this->assertFalse($user->can('update', $payment));
            $this->assertFalse($user->can('delete', $payment));
        }
    }

    private function configureRazorpay(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->razorpay_webhook_secret = Crypt::encryptString('whsecret');
        $gateways->default_provider = 'razorpay';
        $gateways->save();
    }

    private function configureStripe(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_abc123';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_abc123');
        $gateways->stripe_webhook_secret = Crypt::encryptString('whsec_abc123');
        $gateways->save();
    }
}
