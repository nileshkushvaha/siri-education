<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Types\PaidOneToOneType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentReconciliationIssue;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Models\User;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\Enums\PackagePurchaseStatus;
use App\Package\Services\InstructorPackageProposalService;
use App\Package\Services\PackageBenefitRuleService;
use App\Package\Services\PackagePurchaseReconciliationService;
use App\Payments\Enums\PaymentReconciliationIssueStatus;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentReconciliationIssueService;
use App\Payments\Services\PaymentService;
use App\Settings\PaymentGatewaySettings;
use Database\Seeders\PackagePermissionSeeder;
use Database\Seeders\PaymentReconciliationPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Phase 4E.2 — PKG-AUD-014.
 *
 * A verified provider event whose money disagrees with ours must not
 * settle, and must not vanish. Before this phase the refusal was
 * correct but invisible: one activity-log line, no queue, no operator.
 * The provider may be holding real money while the student's package
 * sits at pending_payment forever.
 *
 * The tests deliberately drive the REAL webhook endpoint (signed,
 * through routing and middleware) and the REAL reconciliation sweep,
 * because the claim being made is that both discovery routes reach the
 * SAME issue model through the same single detector.
 */
class PaymentReconciliationIssueTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const string RAZORPAY_SECRET = 'rzp_whsecret';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackagePermissionSeeder::class);
        $this->seed(PaymentReconciliationPermissionSeeder::class);

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
        $gateways->save();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @return array{0: StudentPackagePurchase, 1: Payment} */
    private function purchaseWithAttempt(): array
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
            'name' => '10 paid + 2 bonus',
            'paid_quantity' => 10,
            'bonus_quantity' => 2,
            'total_quantity' => 12,
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

        $payment = app(PaymentService::class)->startAttempt($purchase, 'razorpay', 'PAY-DISCREPANCY01');
        app(PaymentService::class)->recordProviderOrder($payment, 'order_discrepancy_1');

        return [$purchase->refresh(), $payment->refresh()];
    }

    /** @param array<string, mixed> $entity */
    private function postRazorpay(array $entity): TestResponse
    {
        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => $entity]],
        ]);

        return $this->call(
            'POST',
            '/api/webhooks/packages/purchases/razorpay',
            server: [
                'HTTP_X-Razorpay-Signature' => hash_hmac('sha256', (string) $body, self::RAZORPAY_SECRET),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: (string) $body,
        );
    }

    /** @return array<string, mixed> */
    private function entity(Payment $payment, ?int $amountMinor = null, ?string $currency = null): array
    {
        return [
            'id' => 'pay_discrepancy_1',
            'order_id' => $payment->provider_order_id,
            'amount' => $amountMinor ?? $payment->amount_minor,
            'currency' => $currency ?? $payment->currency_code,
            'notes' => ['payment_reference' => $payment->idempotency_key],
        ];
    }

    // ── 18-21. Detection ──────────────────────────────────────────────────

    public function test_an_amount_mismatch_opens_an_issue_and_grants_nothing(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt();

        // The provider says it collected more than we ever approved.
        $this->postRazorpay($this->entity($payment, amountMinor: 999_00))->assertOk();

        $issue = PaymentReconciliationIssue::query()->firstOrFail();

        $this->assertSame(PaymentReconciliationIssueType::AmountMismatch, $issue->issue_type);
        $this->assertSame(PaymentReconciliationIssueStatus::Open, $issue->status);
        $this->assertSame($payment->id, $issue->payment_id);
        // 20.00 GBP unit price x 10 paid units = 200.00 approved.
        $this->assertSame(20000, $issue->expected_amount_minor);
        $this->assertSame(99900, $issue->observed_amount_minor);

        // Nothing was granted and nothing was settled.
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->refresh()->status);
        $this->assertNotSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(0, StudentPackageEntitlement::query()->count());
    }

    public function test_a_currency_mismatch_opens_an_issue_and_grants_nothing(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt();

        $this->postRazorpay($this->entity($payment, currency: 'USD'))->assertOk();

        $issue = PaymentReconciliationIssue::query()->firstOrFail();

        $this->assertSame(PaymentReconciliationIssueType::CurrencyMismatch, $issue->issue_type);
        $this->assertSame('GBP', $issue->expected_currency);
        $this->assertSame('USD', $issue->observed_currency);

        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->refresh()->status);
        $this->assertSame(0, StudentPackageEntitlement::query()->count());
    }

    // ── 22-24. Deduplication ──────────────────────────────────────────────

    public function test_a_redelivered_mismatch_updates_the_existing_issue(): void
    {
        [, $payment] = $this->purchaseWithAttempt();

        $this->postRazorpay($this->entity($payment, amountMinor: 999_00))->assertOk();
        $this->postRazorpay($this->entity($payment, amountMinor: 999_00))->assertOk();
        $this->postRazorpay($this->entity($payment, amountMinor: 999_00))->assertOk();

        // A provider that retries fifty times must produce ONE row an
        // operator can act on, not fifty.
        $this->assertSame(1, PaymentReconciliationIssue::query()->count());

        $issue = PaymentReconciliationIssue::query()->firstOrFail();
        $this->assertSame(3, $issue->occurrence_count);
        $this->assertTrue($issue->last_seen_at->greaterThanOrEqualTo($issue->first_seen_at));
    }

    public function test_the_database_refuses_a_second_open_issue_of_the_same_type(): void
    {
        [, $payment] = $this->purchaseWithAttempt();
        $this->postRazorpay($this->entity($payment, amountMinor: 999_00))->assertOk();

        $this->expectException(QueryException::class);

        // Forged past the service: deduplication is a DB invariant
        // because redelivery is concurrent.
        PaymentReconciliationIssue::query()->create([
            'payment_id' => $payment->id,
            'provider' => 'razorpay',
            'issue_type' => PaymentReconciliationIssueType::AmountMismatch,
            'status' => PaymentReconciliationIssueStatus::Open,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    public function test_different_issue_types_are_tracked_separately(): void
    {
        [, $payment] = $this->purchaseWithAttempt();

        $this->postRazorpay($this->entity($payment, amountMinor: 999_00))->assertOk();
        $this->postRazorpay($this->entity($payment, currency: 'USD'))->assertOk();

        $this->assertSame(2, PaymentReconciliationIssue::query()->count());
    }

    // ── 25-26. Both discovery routes reach the same model ─────────────────

    public function test_the_reconciliation_sweep_records_the_same_issue_model(): void
    {
        [, $payment] = $this->purchaseWithAttempt();

        // The provider confirms payment on poll, but reports collecting
        // a different amount than the purchase is for.
        //
        // This used to force a LOCAL desync instead, because the sweep
        // rebuilt its event from the attempt's own values and so could
        // only ever detect a local disagreement — it compared the row
        // with itself and a genuinely wrong provider amount sailed
        // through. The verifier now carries the provider's reported
        // figures, so the real-world case is the one under test.
        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('fetchOrder')->andReturn([
            'id' => $payment->provider_order_id,
            'status' => 'paid',
            'amount' => 12345,
            'currency' => $payment->currency_code,
        ]);
        $this->app->instance(RazorpayGatewayClient::class, $gateway);

        app(PackagePurchaseReconciliationService::class)->reconcileOne($payment->refresh());

        $issue = PaymentReconciliationIssue::query()->firstOrFail();

        $this->assertSame(PaymentReconciliationIssueType::AmountMismatch, $issue->issue_type);
        // The metadata records WHICH route noticed — the model itself is
        // identical either way, which is the point.
        $this->assertSame('reconciliation', $issue->metadata['source'] ?? null);
        $this->assertSame(0, StudentPackageEntitlement::query()->count());
    }

    // ── 27-28. Security ───────────────────────────────────────────────────

    public function test_an_unverifiable_webhook_creates_no_issue(): void
    {
        [, $payment] = $this->purchaseWithAttempt();

        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => $this->entity($payment, amountMinor: 999_00)]],
        ]);

        $this->call(
            'POST',
            '/api/webhooks/packages/purchases/razorpay',
            server: ['HTTP_X-Razorpay-Signature' => 'forged', 'CONTENT_TYPE' => 'application/json'],
            content: (string) $body,
        )->assertStatus(401);

        // Authenticity is checked before any state is read or written —
        // an attacker must not be able to spam the operator queue.
        $this->assertSame(0, PaymentReconciliationIssue::query()->count());
    }

    public function test_an_unknown_reference_creates_no_issue(): void
    {
        $this->purchaseWithAttempt();

        $this->postRazorpay([
            'id' => 'pay_unknown',
            'order_id' => 'order_belonging_to_nobody',
            'amount' => 999_00,
            'currency' => 'GBP',
            'notes' => ['payment_reference' => 'PAY-DOESNOTEXIST'],
        ])->assertOk();

        // Never create records from an unrecognised payload — an issue
        // must always be evidence ABOUT a real local attempt.
        $this->assertSame(0, PaymentReconciliationIssue::query()->count());
    }

    // ── 29-30. Resolution ─────────────────────────────────────────────────

    public function test_a_later_correct_settlement_auto_resolves_the_open_issue(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt();

        $this->postRazorpay($this->entity($payment, amountMinor: 999_00))->assertOk();
        $this->assertSame(PaymentReconciliationIssueStatus::Open, PaymentReconciliationIssue::query()->firstOrFail()->status);

        // The provider now reports the correct amount.
        $this->postRazorpay($this->entity($payment))->assertOk();

        $issue = PaymentReconciliationIssue::query()->firstOrFail();

        $this->assertSame(PaymentReconciliationIssueStatus::Resolved, $issue->status);
        $this->assertNotNull($issue->resolved_at);
        // Auto-resolution is unattributed — no human did it.
        $this->assertNull($issue->resolved_by);

        $this->assertSame(PackagePurchaseStatus::Paid, $purchase->refresh()->status);
        $this->assertSame(1, StudentPackageEntitlement::query()->count());
    }

    public function test_manual_resolution_never_settles_the_payment(): void
    {
        [$purchase, $payment] = $this->purchaseWithAttempt();
        $this->postRazorpay($this->entity($payment, amountMinor: 999_00))->assertOk();

        $issue = PaymentReconciliationIssue::query()->firstOrFail();

        app(PaymentReconciliationIssueService::class)
            ->resolveManually($issue, $this->manager, 'Refunded by the provider out-of-band.');

        $issue->refresh();

        $this->assertSame(PaymentReconciliationIssueStatus::Resolved, $issue->status);
        $this->assertSame($this->manager->id, $issue->resolved_by);

        // The entire point: closing an operational record moves no money
        // and grants no lessons.
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->refresh()->status);
        $this->assertNotSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(0, StudentPackageEntitlement::query()->count());
    }

    // ── 36. Evidence safety ───────────────────────────────────────────────

    public function test_issue_evidence_carries_no_signature_secret_or_raw_payload(): void
    {
        [, $payment] = $this->purchaseWithAttempt();
        $this->postRazorpay($this->entity($payment, amountMinor: 999_00))->assertOk();

        $serialized = json_encode(PaymentReconciliationIssue::query()->firstOrFail()->toArray());

        foreach ([self::RAZORPAY_SECRET, 'rzp_test_key_id', 'signature', 'notes', 'payload'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                (string) $serialized,
                sprintf('Issue evidence must never carry "%s".', $forbidden),
            );
        }
    }
}
