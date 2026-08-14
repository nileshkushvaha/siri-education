<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Concurrency;

use App\Models\Payment;
use App\Models\StudentPackagePurchase;
use App\Models\User;
use App\Package\Enums\PackagePurchaseStatus;
use App\Payments\Enums\PaymentStatus;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Booking\Concurrency\ConcurrencyTestCase;

/**
 * Phase 4E.2 — the REAL concurrency proof for PKG-AUD-004.
 *
 * A sequential PHPUnit test that calls start() twice proves nothing
 * here: the bug was a read-then-insert with no lock, and sequential
 * calls never overlap, so such a test passes against the broken code.
 * These tests therefore use the project's real multi-process harness —
 * separate PHP processes, separate MySQL connections, released
 * simultaneously from a shared start barrier.
 *
 * Two properties are asserted, because closing only the first would
 * still leave a double charge:
 *
 *   A. exactly ONE open Payment attempt survives;
 *   B. the gateway's createOrder() was invoked exactly ONCE.
 *
 * B is the one that actually matters commercially and the one a
 * database assertion alone cannot see: if two workers each created an
 * order, only one id is ever stored, so `payments` looks perfect while
 * a second live order sits at Razorpay waiting to be paid. The counting
 * client writes every invocation to a shared file precisely so that
 * second order cannot hide.
 */
class PackageCheckoutConcurrencyTest extends ConcurrencyTestCase
{
    private string $orderLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderLog = tempnam(sys_get_temp_dir(), 'pkg-orders-');
        file_put_contents($this->orderLog, '');

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->save();
    }

    protected function tearDown(): void
    {
        @unlink($this->orderLog);

        parent::tearDown();
    }

    /**
     * A pending purchase. The proposal/settlement pipeline has its own
     * suite; what the race needs is simply something payable.
     */
    private function purchase(): StudentPackagePurchase
    {
        // A real user: payments.user_id carries a genuine foreign key,
        // so a placeholder id would fail inside the child process where
        // the error is far harder to see.
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Schema::disableForeignKeyConstraints();

        $purchase = StudentPackagePurchase::query()->create([
            'proposal_id' => (string) Str::uuid(),
            'student_id' => $student->id,
            'reference' => 'PKG-'.strtoupper(Str::random(12)),
            'amount_minor' => 49900,
            'currency_code' => 'INR',
            'status' => PackagePurchaseStatus::PendingPayment,
            'accepted_at' => now(),
        ]);

        Schema::enableForeignKeyConstraints();

        return $purchase;
    }

    /** @return list<string> every order id the gateway actually minted */
    private function providerOrdersCreated(): array
    {
        $lines = array_filter(array_map('trim', explode("\n", (string) file_get_contents($this->orderLog))));

        return array_values($lines);
    }

    // ── 9-13. The race ────────────────────────────────────────────────────

    public function test_two_concurrent_checkouts_create_one_attempt_and_one_provider_order(): void
    {
        $purchase = $this->purchase();

        $verdicts = $this->race([
            ['package-checkout-start', ['purchase_id' => $purchase->id, 'order_log' => $this->orderLog]],
            ['package-checkout-start', ['purchase_id' => $purchase->id, 'order_log' => $this->orderLog]],
        ]);

        $attempts = Payment::query()->where('payable_id', $purchase->id)->get();

        // A — exactly one attempt, and it is open.
        $this->assertCount(1, $attempts, 'Two concurrent checkouts must not create two payment attempts.');
        $this->assertTrue($attempts->first()->status->isOpen());

        // B — the gateway saw exactly one order creation.
        $this->assertCount(
            1,
            $this->providerOrdersCreated(),
            'Two concurrent checkouts must not create two live gateway orders.',
        );

        // Both callers converge on the same checkout. The harness wraps
        // each worker's return value in an {ok, op, result} envelope.
        $results = array_filter(array_column($verdicts, 'result'));

        $this->assertCount(2, $results, 'Both workers must succeed: '.json_encode($verdicts));

        $paymentIds = array_unique(array_column($results, 'payment_id'));
        $this->assertCount(1, $paymentIds, 'Both callers must reference the same payment attempt.');

        $orderIds = array_unique(array_filter(array_column($results, 'order_id')));
        $this->assertCount(1, $orderIds, 'Both callers must reference the same provider order.');
        $this->assertSame($this->providerOrdersCreated()[0], reset($orderIds));
    }

    public function test_three_concurrent_checkouts_still_create_one_provider_order(): void
    {
        $purchase = $this->purchase();

        $this->race([
            ['package-checkout-start', ['purchase_id' => $purchase->id, 'order_log' => $this->orderLog]],
            ['package-checkout-start', ['purchase_id' => $purchase->id, 'order_log' => $this->orderLog]],
            ['package-checkout-start', ['purchase_id' => $purchase->id, 'order_log' => $this->orderLog]],
        ]);

        // Widened deliberately: a two-worker race can pass by luck on a
        // fast machine, three is a stronger squeeze on both windows.
        $this->assertSame(1, Payment::query()->where('payable_id', $purchase->id)->count());
        $this->assertCount(1, $this->providerOrdersCreated());
    }

    public function test_two_different_purchases_each_get_their_own_order(): void
    {
        $first = $this->purchase();
        $second = $this->purchase();

        $this->race([
            ['package-checkout-start', ['purchase_id' => $first->id, 'order_log' => $this->orderLog]],
            ['package-checkout-start', ['purchase_id' => $second->id, 'order_log' => $this->orderLog]],
        ]);

        // The invariant is per-payable. If it were global, the platform's
        // entire checkout would serialize behind one student.
        $this->assertSame(1, Payment::query()->where('payable_id', $first->id)->count());
        $this->assertSame(1, Payment::query()->where('payable_id', $second->id)->count());
        $this->assertCount(2, $this->providerOrdersCreated());
    }

    // ── 17. No second purchase ────────────────────────────────────────────

    public function test_no_race_creates_a_second_purchase(): void
    {
        $purchase = $this->purchase();

        $this->race([
            ['package-checkout-start', ['purchase_id' => $purchase->id, 'order_log' => $this->orderLog]],
            ['package-checkout-start', ['purchase_id' => $purchase->id, 'order_log' => $this->orderLog]],
        ]);

        // Scoped to this proposal: the harness commits for real (child
        // processes must see the fixtures), so rows from earlier tests
        // in this class are still present and a global count would be
        // measuring the wrong thing.
        $this->assertSame(1, StudentPackagePurchase::query()->where('proposal_id', $purchase->proposal_id)->count());
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->refresh()->status);
    }

    // ── 16. Ambiguous provider outcome ────────────────────────────────────

    public function test_an_ambiguous_gateway_failure_closes_the_attempt_without_a_second_order(): void
    {
        $purchase = $this->purchase();

        // The gateway creates the order and then never answers — the
        // worst case, because an order may genuinely exist that we have
        // no id for.
        $this->race([
            ['package-checkout-start', ['purchase_id' => $purchase->id, 'order_log' => $this->orderLog, 'simulate_timeout' => true]],
            ['package-checkout-start', ['purchase_id' => $purchase->id, 'order_log' => $this->orderLog, 'simulate_timeout' => true]],
        ]);

        $attempts = Payment::query()->where('payable_id', $purchase->id)->get();

        // Exactly one attempt, closed as Failed — durable evidence that
        // we tried, and the open slot released so the student may retry.
        $this->assertCount(1, $attempts);
        $this->assertSame(PaymentStatus::Failed, $attempts->first()->status);
        $this->assertSame('provider_order_failed', $attempts->first()->failure_code);

        // Crucially: the losing worker did NOT take the ambiguity as an
        // invitation to create its own order.
        $this->assertCount(
            1,
            $this->providerOrdersCreated(),
            'An ambiguous failure must never trigger a second external order.',
        );
    }

    public function test_a_retry_after_a_failed_attempt_creates_a_new_attempt_and_order(): void
    {
        $purchase = $this->purchase();

        $this->race([
            ['package-checkout-start', ['purchase_id' => $purchase->id, 'order_log' => $this->orderLog, 'simulate_timeout' => true]],
        ]);

        $this->assertSame(PaymentStatus::Failed, Payment::query()->where('payable_id', $purchase->id)->firstOrFail()->status);

        // The concurrency fix must not have broken legitimate retry
        // (stop condition 5): a terminal attempt frees the slot.
        $this->race([
            ['package-checkout-start', ['purchase_id' => $purchase->id, 'order_log' => $this->orderLog]],
        ]);

        $attempts = Payment::query()->where('payable_id', $purchase->id)->orderBy('created_at')->get();

        $this->assertCount(2, $attempts, 'A retry must create a NEW attempt, never mutate the failed one.');
        $this->assertSame(PaymentStatus::Failed, $attempts->first()->status);
        $this->assertTrue($attempts->last()->status->isOpen());
        $this->assertCount(2, $this->providerOrdersCreated());
    }
}
