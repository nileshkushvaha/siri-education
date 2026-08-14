<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Types\PaidOneToOneType;
use App\Livewire\Frontend\Student\PackageProposals;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Models\User;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\Enums\PackagePurchaseStatus;
use App\Package\Services\InstructorPackageProposalService;
use App\Package\Services\PackageBenefitRuleService;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentService;
use App\Settings\PaymentGatewaySettings;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 4B.3 — the package settlement webhook endpoint.
 *
 * These tests cover the transport contract specifically: authenticity
 * before anything else, no mutation from an unverifiable request, and
 * a response code that tells the provider the truth — 200 to stop
 * retrying, 500 when a retry is genuinely wanted.
 *
 * Signature verification itself is the shared
 * PaymentWebhookSignatureService; these tests prove it is actually
 * wired in, not that HMAC works.
 */
class PackagePurchaseWebhookTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const string RAZORPAY_SECRET = 'rzp_whsecret';

    private const string STRIPE_SECRET = 'whsec_test_secret';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackagePermissionSeeder::class);
        foreach (['manager', 'instructor', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->manager = User::factory()->create(['status' => 'active']);
        $this->manager->assignRole('manager');

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->razorpay_webhook_secret = Crypt::encryptString(self::RAZORPAY_SECRET);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test');
        $gateways->stripe_webhook_secret = Crypt::encryptString(self::STRIPE_SECRET);
        $gateways->save();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @return array{0: StudentPackagePurchase, 1: Payment} */
    private function purchaseWithAttempt(string $provider): array
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
            'validity_days' => 90,
        ]);

        $proposals = app(InstructorPackageProposalService::class);
        $accepted = $proposals->acceptProposal(
            $proposals->approve($proposals->proposeAndSubmit(new CreatePackageProposalData(
                instructorId: $instructor->id,
                studentId: $student->id,
                packageBenefitRuleId: $rule->id,
                subjectId: $this->seedLessonSubject()->id,
                academicLevelId: null,
            )), $this->manager, null, null),
            $student,
        );

        $purchase = StudentPackagePurchase::query()->where('proposal_id', $accepted->id)->firstOrFail();

        $payment = app(PaymentService::class)->startAttempt($purchase, $provider, 'PAY-WEBHOOKTEST01');
        app(PaymentService::class)->recordProviderOrder($payment, $provider === 'stripe' ? 'pi_test_1' : 'order_test_1');

        return [$purchase->refresh(), $payment->refresh()];
    }

    /** @return array<string, mixed> */
    private function razorpayPayload(Payment $payment, string $event = 'payment.captured', ?int $amountMinor = null): array
    {
        return [
            'event' => $event,
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_razorpay_1',
                'order_id' => $payment->provider_order_id,
                'amount' => $amountMinor ?? $payment->amount_minor,
                'currency' => $payment->currency_code,
                'notes' => ['payment_reference' => $payment->idempotency_key],
            ]]],
        ];
    }

    /** @return array<string, mixed> */
    private function stripePayload(Payment $payment, string $type = 'payment_intent.succeeded'): array
    {
        return [
            'type' => $type,
            'data' => ['object' => [
                'id' => $payment->provider_order_id,
                'amount' => $payment->amount_minor,
                'amount_received' => $payment->amount_minor,
                'currency' => strtolower((string) $payment->currency_code),
                'metadata' => ['payment_reference' => $payment->idempotency_key],
            ]],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function postRazorpay(array $payload, ?string $secret = self::RAZORPAY_SECRET): TestResponse
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            '/api/webhooks/packages/purchases/razorpay',
            server: ['HTTP_X-Razorpay-Signature' => hash_hmac('sha256', $body, (string) $secret), 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
    }

    /** @param array<string, mixed> $payload */
    private function postStripe(array $payload, ?string $secret = self::STRIPE_SECRET): TestResponse
    {
        $body = json_encode($payload);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", (string) $secret);

        return $this->call(
            'POST',
            '/api/webhooks/packages/purchases/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
    }

    // ── 1-6. Webhook security ─────────────────────────────────────────────

    public function test_a_valid_razorpay_webhook_settles_and_activates(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');

        $this->postRazorpay($this->razorpayPayload($payment))
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PackagePurchaseStatus::Paid, $purchase->fresh()->status);
        $this->assertSame(1, StudentPackageEntitlement::query()->count());
    }

    public function test_an_invalid_razorpay_signature_changes_nothing(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');

        $this->postRazorpay($this->razorpayPayload($payment), secret: 'wrong-secret')->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_a_valid_stripe_webhook_settles_and_activates(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('stripe');

        $this->postStripe($this->stripePayload($payment))
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $this->assertSame(PackagePurchaseStatus::Paid, $purchase->fresh()->status);
        $this->assertSame(1, StudentPackageEntitlement::query()->count());
    }

    public function test_an_invalid_stripe_signature_changes_nothing(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('stripe');

        $this->postStripe($this->stripePayload($payment), secret: 'whsec_wrong')->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_an_unknown_provider_is_rejected(): void
    {
        $this->postJson('/api/webhooks/packages/purchases/paypal', [])->assertNotFound();
    }

    public function test_an_unknown_payment_reference_is_acknowledged_without_creating_anything(): void
    {
        $this->postRazorpay([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_ghost', 'order_id' => 'order_ghost', 'amount' => 5000, 'currency' => 'GBP',
                'notes' => ['payment_reference' => 'PAY-DOESNOTEXIST'],
            ]]],
        ])->assertOk()->assertJson(['status' => 'ignored']);

        // Never invent commercial records from a webhook payload.
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('student_package_purchases', 0);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_a_malformed_payload_is_rejected(): void
    {
        $body = 'not json at all';

        $this->call(
            'POST',
            '/api/webhooks/packages/purchases/razorpay',
            server: ['HTTP_X-Razorpay-Signature' => hash_hmac('sha256', $body, self::RAZORPAY_SECRET), 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        )->assertStatus(401);
    }

    // ── Cross-domain safety ───────────────────────────────────────────────

    /** The package endpoint must never settle another domain's payment. */
    public function test_a_payment_for_another_payable_type_is_ignored(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');
        $payment->forceFill(['payable_type' => 'some_other_payable'])->save();

        $this->postRazorpay($this->razorpayPayload($payment->refresh()))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    // ── Validation ────────────────────────────────────────────────────────

    public function test_an_amount_mismatch_is_acknowledged_but_never_activates(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');

        // Correctly signed, but claims a different amount was collected.
        $this->postRazorpay($this->razorpayPayload($payment, amountMinor: 100))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    // ── Idempotency and ordering ──────────────────────────────────────────

    public function test_a_replayed_success_webhook_creates_one_entitlement(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');

        $this->postRazorpay($this->razorpayPayload($payment))->assertOk()->assertJson(['status' => 'processed']);
        $this->postRazorpay($this->razorpayPayload($payment))->assertOk()->assertJson(['status' => 'replayed']);
        $this->postRazorpay($this->razorpayPayload($payment))->assertOk()->assertJson(['status' => 'replayed']);

        $this->assertSame(1, StudentPackageEntitlement::query()->count());
        $this->assertSame(1, StudentPackagePurchase::query()->count());
    }

    public function test_a_failure_webhook_arriving_after_success_never_reverses_it(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');

        $this->postRazorpay($this->razorpayPayload($payment))->assertOk();
        $this->postRazorpay($this->razorpayPayload($payment, event: 'payment.failed'))->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PackagePurchaseStatus::Paid, $purchase->fresh()->status);
        $this->assertSame(1, StudentPackageEntitlement::query()->count());
    }

    public function test_a_failure_webhook_closes_the_attempt_but_not_the_purchase(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');

        $this->postRazorpay($this->razorpayPayload($payment, event: 'payment.failed'))->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_an_unactionable_event_type_is_acknowledged(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');

        $this->postRazorpay($this->razorpayPayload($payment, event: 'payment.dispute.created'))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    // ── Retryable failure ─────────────────────────────────────────────────

    /**
     * The reason this controller does not blanket-200 like the wallet
     * one: a rolled-back settlement must NOT tell the provider to stop.
     */
    public function test_a_failed_activation_answers_retryable_and_persists_nothing(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');

        StudentPackageEntitlement::creating(function (): void {
            throw new \RuntimeException('Simulated activation failure.');
        });

        $this->withoutExceptionHandling(['*'])
            ->postRazorpay($this->razorpayPayload($payment))
            ->assertStatus(500)
            ->assertJson(['status' => 'retry']);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->fresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    // ── Student UI after settlement ───────────────────────────────────────

    public function test_the_student_page_shows_an_active_package_and_no_pay_button_after_settlement(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');
        $this->postRazorpay($this->razorpayPayload($payment))->assertOk();

        Livewire::actingAs($purchase->student)
            ->test(PackageProposals::class)
            ->assertSee('Paid')
            ->assertSee('25')            // remaining lessons
            ->assertSee('Valid until')
            ->assertDontSee('Pay Now')
            ->assertDontSee('Continue Payment');
    }

    public function test_a_no_expiry_package_says_so_instead_of_showing_a_date(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');
        // The offer carried no validity limit.
        $purchase->proposal->forceFill(['validity_days' => null])->saveQuietly();

        $this->postRazorpay($this->razorpayPayload($payment))->assertOk();

        Livewire::actingAs($purchase->student)
            ->test(PackageProposals::class)
            ->assertSee('No expiry');
    }

    /**
     * The interrupted-settlement window: money confirmed, activation
     * lagging. The student must never be offered a second payment.
     */
    public function test_a_paid_but_unactivated_purchase_shows_activating_and_hides_the_pay_button(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');
        app(PaymentService::class)->transition($payment, PaymentStatus::Paid);

        Livewire::actingAs($purchase->student)
            ->test(PackageProposals::class)
            ->assertSee('Payment received')
            ->assertSee('being activated')
            ->assertDontSee('Pay Now')
            ->assertDontSee('Continue Payment');
    }

    public function test_an_unpaid_purchase_still_shows_the_pay_button(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');

        Livewire::actingAs($purchase->student)
            ->test(PackageProposals::class)
            ->assertSee('Continue Payment');
    }

    // ── Secrets ───────────────────────────────────────────────────────────

    public function test_no_signature_or_secret_is_ever_persisted(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt('razorpay');
        $this->postRazorpay($this->razorpayPayload($payment))->assertOk();

        $columns = Schema::getColumnListing('payments');
        foreach (['signature', 'webhook_secret', 'raw_payload'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        $metadata = json_encode($payment->fresh()->metadata);
        $this->assertStringNotContainsString(self::RAZORPAY_SECRET, (string) $metadata);
    }
}
