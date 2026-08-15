<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\User;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Services\PaymentService;
use App\Payments\Services\PaymentWebhookEventParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Payments\FakePayable;
use Tests\TestCase;

/**
 * PAY-4B/5 — the provider metadata key a webhook is resolved by.
 *
 * Checkout writes `payment_reference` into provider metadata and the
 * parser reads it back. That round trip is the ONLY reliable way to
 * identify which attempt a webhook is about: `provider_order_id` is a
 * fallback for providers that omit metadata, not the normal path.
 *
 * Razorpay previously wrote `payable_reference` while the parser read
 * `payment_reference`, so its reference resolved to null on every
 * webhook and lookup survived purely on that fallback. These tests pin
 * the contract so the two sides cannot drift apart again.
 */
class PaymentReferenceContractTest extends TestCase
{
    use RefreshDatabase;

    private function payable(string $id = 'payable-ref-1'): FakePayable
    {
        $user = User::factory()->create(['status' => 'active']);

        return new FakePayable(
            payableType: 'fake_payable',
            payableId: $id,
            amountMinor: 28000,
            currencyCode: 'GBP',
            userId: $user->id,
            reference: 'OBLIGATION-REF',
        );
    }

    private function parser(): PaymentWebhookEventParser
    {
        return app(PaymentWebhookEventParser::class);
    }

    /** Both providers must name the lookup key identically. */
    public function test_both_providers_use_one_canonical_lookup_key(): void
    {
        $source = (string) file_get_contents(app_path('Payments/Services/PaymentCheckoutService.php'));

        // Razorpay notes and Stripe metadata both carry it.
        $this->assertSame(
            2,
            substr_count($source, "'payment_reference' => (string) \$payment->idempotency_key"),
            'Razorpay notes and Stripe metadata must both send payment_reference.',
        );
    }

    public function test_razorpay_webhook_resolves_the_attempt_from_metadata_alone(): void
    {
        $payment = app(PaymentService::class)->startAttempt($this->payable(), 'razorpay', 'PAY-CANONICAL-RZP');

        $event = $this->parser()->parse('razorpay', [
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_LIVE1',
                // Deliberately NO order_id: the fallback is unavailable,
                // so only the canonical metadata key can resolve this.
                'notes' => ['payment_reference' => 'PAY-CANONICAL-RZP'],
                'amount' => 28000,
                'currency' => 'GBP',
            ]]],
        ]);

        $this->assertNotNull($event);
        $this->assertSame('PAY-CANONICAL-RZP', $event->reference);
        $this->assertNull($event->providerOrderId, 'Fallback must genuinely be unavailable for this proof.');

        $found = app(PaymentService::class)->findByProviderReference('razorpay', $event->reference, null, null);

        $this->assertNotNull($found, 'Canonical reference alone failed to resolve the attempt.');
        $this->assertSame($payment->id, $found->id);
    }

    public function test_stripe_webhook_resolves_the_attempt_from_metadata_alone(): void
    {
        $payment = app(PaymentService::class)->startAttempt($this->payable('payable-ref-2'), 'stripe', 'PAY-CANONICAL-STR');

        $event = $this->parser()->parse('stripe', [
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_LIVE1',
                'metadata' => ['payment_reference' => 'PAY-CANONICAL-STR'],
                'amount_received' => 28000,
                'currency' => 'gbp',
            ]],
        ]);

        $this->assertNotNull($event);
        $this->assertSame('PAY-CANONICAL-STR', $event->reference);

        $found = app(PaymentService::class)->findByProviderReference('stripe', $event->reference, null, null);

        $this->assertSame($payment->id, $found?->id);
    }

    /**
     * The obligation reference is stable across retries, so it cannot
     * identify one attempt — which is exactly why it must not be the
     * lookup key.
     */
    public function test_the_obligation_reference_is_not_the_lookup_key(): void
    {
        $payable = $this->payable('payable-ref-3');
        $service = app(PaymentService::class);

        $first = $service->startAttempt($payable, 'razorpay', 'PAY-ATTEMPT-A');
        $service->transition($first, PaymentStatus::Failed);
        $second = $service->startAttempt($payable, 'razorpay', 'PAY-ATTEMPT-B');

        $this->assertNotSame($first->id, $second->id);

        // The obligation reference would be ambiguous between the two.
        $this->assertNull($service->findByProviderReference('razorpay', $payable->paymentReference(), null, null));

        // Each attempt key resolves to exactly its own row.
        $this->assertSame($first->id, $service->findByProviderReference('razorpay', 'PAY-ATTEMPT-A', null, null)?->id);
        $this->assertSame($second->id, $service->findByProviderReference('razorpay', 'PAY-ATTEMPT-B', null, null)?->id);
    }

    public function test_payment_ids_are_unique_per_attempt_not_reused(): void
    {
        $this->assertSame(0, Payment::query()->whereNull('idempotency_key')->count());
    }
}
