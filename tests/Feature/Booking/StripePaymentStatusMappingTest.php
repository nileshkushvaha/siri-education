<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Payments\StripePaymentProvider;
use App\Booking\Registry\PaymentProviderRegistry;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 16C — proves StripePaymentProvider::fetchStatus() normalizes
 * every Stripe PaymentIntent status this phase's lifecycle enum needs
 * to represent (processing, unknown, requires_* -> pending, canceled),
 * never silently mapping an unrecognized/ambiguous provider status to
 * a false "captured" or "failed".
 */
class StripePaymentStatusMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_mapping123';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_mapping123');
        $gateways->stripe_webhook_secret = Crypt::encryptString('whsec_mapping123');
        $gateways->save();
    }

    private function providerWithFakeIntentStatus(string $stripeStatus): StripePaymentProvider
    {
        $mock = Mockery::mock(StripeGatewayClient::class);
        $mock->shouldReceive('retrievePaymentIntent')->andReturn(['id' => 'pi_mapping_test', 'status' => $stripeStatus]);
        $this->app->instance(StripeGatewayClient::class, $mock);

        return app(PaymentProviderRegistry::class)->get('stripe');
    }

    /** @return array<string, array{0: string, 1: BookingPaymentRecordStatus}> */
    public static function statusMappings(): array
    {
        return [
            'succeeded -> Captured' => ['succeeded', BookingPaymentRecordStatus::Captured],
            'processing -> Processing' => ['processing', BookingPaymentRecordStatus::Processing],
            'requires_payment_method -> Pending' => ['requires_payment_method', BookingPaymentRecordStatus::Pending],
            'requires_confirmation -> Pending' => ['requires_confirmation', BookingPaymentRecordStatus::Pending],
            'requires_action -> Pending (SCA/3DS in progress client-side)' => ['requires_action', BookingPaymentRecordStatus::Pending],
            'canceled -> Cancelled' => ['canceled', BookingPaymentRecordStatus::Cancelled],
            'some_future_unrecognized_status -> Unknown, never a false positive/negative' => ['some_future_unrecognized_status', BookingPaymentRecordStatus::Unknown],
        ];
    }

    #[DataProvider('statusMappings')]
    public function test_fetch_status_normalizes_stripe_intent_status(string $stripeStatus, BookingPaymentRecordStatus $expected): void
    {
        $provider = $this->providerWithFakeIntentStatus($stripeStatus);

        $result = $provider->fetchStatus('pi_mapping_test');

        $this->assertSame($expected, $result->recordStatus);
        $this->assertSame($stripeStatus, $result->providerStatus);
    }

    public function test_unknown_status_is_never_terminal_and_must_be_reconciled_again(): void
    {
        $provider = $this->providerWithFakeIntentStatus('some_wildly_unexpected_status');

        $result = $provider->fetchStatus('pi_mapping_test');

        $this->assertFalse($result->recordStatus->isTerminal());
    }
}
