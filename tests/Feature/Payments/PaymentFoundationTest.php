<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Services\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Payments\FakePayable;
use Tests\TestCase;

/**
 * Phase 4B.1 — the generic payment-attempt foundation, exercised
 * against a test-only Payable because the first production consumer
 * (StudentPackagePurchase) arrives in Phase 4B.2.
 *
 * These tests also pin the properties that make this table safe to
 * build on: attempt cardinality, provider-reference uniqueness,
 * idempotency, morph-alias storage, and the absence of any credential
 * column.
 */
class PaymentFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function payable(string $id = 'payable-1', int $amountMinor = 28000): FakePayable
    {
        $user = User::factory()->create(['status' => 'active']);

        return new FakePayable(
            payableType: 'fake_payable',
            payableId: $id,
            amountMinor: $amountMinor,
            currencyCode: 'GBP',
            userId: $user->id,
            reference: 'REF-'.$id,
        );
    }

    // ── 1. Morph alias, never a FQCN ──────────────────────────────────────

    public function test_payable_type_stores_a_stable_alias_not_a_fqcn(): void
    {
        $payment = $this->service()->startAttempt($this->payable(), 'razorpay');

        $this->assertSame('fake_payable', $payment->payable_type);
        $this->assertStringNotContainsString('\\', $payment->payable_type);
        $this->assertStringNotContainsString('App\\Models', $payment->payable_type);
    }

    // ── 2. One payable → many attempts ────────────────────────────────────

    public function test_a_payable_can_have_multiple_payment_attempts(): void
    {
        $payable = $this->payable();

        $first = $this->service()->startAttempt($payable, 'razorpay');
        $this->service()->transition($first, PaymentStatus::Failed, ['failure_code' => 'card_declined']);

        $second = $this->service()->startAttempt($payable, 'razorpay');
        $this->service()->transition($second, PaymentStatus::Paid);

        $attempts = $this->service()->attemptsFor($payable);

        $this->assertCount(2, $attempts);
        $this->assertNotSame($first->id, $second->id);
        // The failed attempt survives — retry never overwrites history.
        $this->assertSame(PaymentStatus::Failed, $first->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $second->fresh()->status);
        $this->assertTrue($this->service()->isPaid($payable));
    }

    public function test_a_second_attempt_cannot_open_while_one_is_still_open(): void
    {
        $payable = $this->payable();
        $this->service()->startAttempt($payable, 'razorpay');

        $this->expectException(PaymentException::class);
        $this->service()->startAttempt($payable, 'razorpay');
    }

    // ── 3/4. Amount + currency storage ────────────────────────────────────

    public function test_amount_is_stored_as_integer_minor_units(): void
    {
        $payment = $this->service()->startAttempt($this->payable(amountMinor: 28000), 'razorpay');

        $this->assertSame(28000, $payment->amount_minor);
        $this->assertIsInt($payment->fresh()->amount_minor);
    }

    public function test_a_non_positive_amount_is_rejected(): void
    {
        $this->expectException(PaymentException::class);
        $this->service()->startAttempt($this->payable(amountMinor: 0), 'razorpay');
    }

    public function test_a_non_positive_amount_is_rejected_at_database_level(): void
    {
        $this->expectException(QueryException::class);
        Payment::query()->create([
            'payable_type' => 'fake_payable',
            'payable_id' => 'x',
            'provider' => 'razorpay',
            'amount_minor' => 0,
            'currency_code' => 'GBP',
        ]);
    }

    public function test_currency_code_is_stored_alongside_the_amount(): void
    {
        $payment = $this->service()->startAttempt($this->payable(), 'razorpay');

        $this->assertSame('GBP', $payment->currency_code);
    }

    // ── 5/6/7. Uniqueness guarantees ──────────────────────────────────────

    public function test_provider_order_id_is_unique_per_provider(): void
    {
        $this->service()->transition(
            $this->service()->startAttempt($this->payable('a'), 'razorpay'),
            PaymentStatus::Processing,
            ['provider_order_id' => 'order_dup'],
        );

        $second = $this->service()->startAttempt($this->payable('b'), 'razorpay');

        $this->expectException(QueryException::class);
        $this->service()->transition($second, PaymentStatus::Processing, ['provider_order_id' => 'order_dup']);
    }

    public function test_the_same_provider_order_id_may_recur_across_different_providers(): void
    {
        $this->service()->transition(
            $this->service()->startAttempt($this->payable('a'), 'razorpay'),
            PaymentStatus::Processing,
            ['provider_order_id' => 'shared_id'],
        );

        $stripe = $this->service()->startAttempt($this->payable('b'), 'stripe');
        $updated = $this->service()->transition($stripe, PaymentStatus::Processing, ['provider_order_id' => 'shared_id']);

        $this->assertSame('shared_id', $updated->provider_order_id);
    }

    public function test_provider_payment_id_is_unique_per_provider(): void
    {
        $this->service()->transition(
            $this->service()->startAttempt($this->payable('a'), 'razorpay'),
            PaymentStatus::Paid,
            ['provider_payment_id' => 'pay_dup'],
        );

        $second = $this->service()->startAttempt($this->payable('b'), 'razorpay');

        $this->expectException(QueryException::class);
        $this->service()->transition($second, PaymentStatus::Paid, ['provider_payment_id' => 'pay_dup']);
    }

    public function test_idempotency_key_is_unique(): void
    {
        $this->service()->startAttempt($this->payable('a'), 'razorpay', 'idem-1');

        $this->expectException(QueryException::class);
        $this->service()->startAttempt($this->payable('b'), 'razorpay', 'idem-1');
    }

    public function test_multiple_attempts_may_omit_the_idempotency_key(): void
    {
        $this->service()->startAttempt($this->payable('a'), 'razorpay');
        $second = $this->service()->startAttempt($this->payable('b'), 'razorpay');

        // NULLs are distinct in MySQL unique indexes — no false collision.
        $this->assertNull($second->idempotency_key);
        $this->assertSame(2, Payment::query()->count());
    }

    // ── 8/9. Status transitions ───────────────────────────────────────────

    public function test_legal_status_transitions_are_allowed(): void
    {
        $payment = $this->service()->startAttempt($this->payable(), 'razorpay');
        $this->assertSame(PaymentStatus::Pending, $payment->status);

        $processing = $this->service()->transition($payment, PaymentStatus::Processing);
        $this->assertSame(PaymentStatus::Processing, $processing->status);

        $paid = $this->service()->transition($processing, PaymentStatus::Paid);
        $this->assertSame(PaymentStatus::Paid, $paid->status);
        $this->assertNotNull($paid->paid_at);
    }

    public function test_illegal_status_transitions_are_rejected(): void
    {
        $payment = $this->service()->startAttempt($this->payable(), 'razorpay');
        $paid = $this->service()->transition($payment, PaymentStatus::Paid);

        // Paid is terminal — a settled attempt is never reopened or reused.
        $this->expectException(PaymentException::class);
        $this->service()->transition($paid, PaymentStatus::Processing);
    }

    public function test_terminal_statuses_allow_no_further_transition(): void
    {
        foreach ([PaymentStatus::Paid, PaymentStatus::Failed, PaymentStatus::Cancelled] as $terminal) {
            $this->assertTrue($terminal->isTerminal());
            $this->assertSame([], $terminal->allowedTransitions());
        }

        $this->assertFalse(PaymentStatus::Pending->isTerminal());
        $this->assertTrue(PaymentStatus::Pending->isOpen());
        $this->assertTrue(PaymentStatus::Processing->isOpen());
    }

    public function test_failed_attempt_records_failure_details_and_timestamp(): void
    {
        $payment = $this->service()->startAttempt($this->payable(), 'razorpay');

        $failed = $this->service()->transition($payment, PaymentStatus::Failed, [
            'failure_code' => 'card_declined',
            'failure_message' => 'The card was declined.',
        ]);

        $this->assertSame('card_declined', $failed->failure_code);
        $this->assertNotNull($failed->failed_at);
        $this->assertNull($failed->paid_at);
    }

    // ── 10. No credential storage ─────────────────────────────────────────

    public function test_the_payments_table_holds_no_credential_or_signature_columns(): void
    {
        $columns = Schema::getColumnListing('payments');

        foreach (['card_number', 'cvv', 'signature', 'provider_signature', 'secret', 'token', 'upi_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "payments must never store [{$forbidden}].");
        }
    }

    // ── Historical safety ─────────────────────────────────────────────────

    public function test_a_payment_attempt_cannot_be_deleted(): void
    {
        $payment = $this->service()->startAttempt($this->payable(), 'razorpay');

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $payment->delete();
    }

    /** The payable pair carries no FK by design — ownership is a service-boundary concern. */
    public function test_payments_has_no_foreign_key_on_the_polymorphic_payable_columns(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('payments'))
            ->flatMap(fn (array $fk): array => $fk['columns'])
            ->all();

        $this->assertNotContains('payable_id', $foreignKeys);
        $this->assertNotContains('payable_type', $foreignKeys);
    }
}
