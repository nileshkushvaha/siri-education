<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\RazorpayX;

use App\Earnings\DTOs\PayoutInitiationRequest;
use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\PayoutFailureCategory;
use App\Earnings\Enums\PayoutMethodType;
use App\Earnings\Exceptions\PayoutProviderException;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXHealthResult;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXPayoutResult;
use App\Earnings\Providers\RazorpayX\RazorpayXInstructorPayoutProvider;
use App\Earnings\Providers\RazorpayX\RazorpayXPayoutClientInterface;
use App\Earnings\Providers\RazorpayX\RazorpayXRequestException;
use App\Models\InstructorPayoutDestinationProviderLink;
use App\Models\InstructorPayoutMethod;
use App\Settings\RazorpayXPayoutSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RazorpayXInstructorPayoutProviderTest extends TestCase
{
    use RefreshDatabase;

    private const string KEY_SECRET = 'razorpayx-key-secret';

    private const string WEBHOOK_SECRET = 'razorpayx-webhook-secret';

    private MockInterface $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(RazorpayXPayoutClientInterface::class);
        $this->app->instance(RazorpayXPayoutClientInterface::class, $this->client);
    }

    private function configure(bool $enabled = true): RazorpayXPayoutSettings
    {
        $settings = app(RazorpayXPayoutSettings::class);
        $settings->razorpayx_enabled = $enabled;
        $settings->razorpayx_environment = 'test';
        $settings->razorpayx_key_id = 'rzp_test_abc123XYZ';
        $settings->razorpayx_key_secret = Crypt::encryptString(self::KEY_SECRET);
        $settings->razorpayx_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $settings->razorpayx_account_number = '1234567890';
        $settings->razorpayx_expected_outbound_ips = ['203.0.113.10'];
        $settings->razorpayx_ip_allowlisting_confirmed_at = now()->toIso8601String();
        $settings->razorpayx_default_mode = 'IMPS';
        $settings->razorpayx_default_purpose = 'payout';
        $settings->save();

        return $settings;
    }

    private function provider(): RazorpayXInstructorPayoutProvider
    {
        return app(RazorpayXInstructorPayoutProvider::class);
    }

    // ── Capabilities ─────────────────────────────────────────────────

    public function test_capabilities_are_india_inr_only(): void
    {
        $caps = $this->provider()->capabilities();

        $this->assertSame(['INR'], $caps->supportedCurrencies);
        $this->assertSame(['IN'], $caps->supportedInstructorCountries);
        $this->assertSame(['IN'], $caps->supportedDestinationCountries);
        $this->assertTrue($caps->requiresContact);
        $this->assertTrue($caps->requiresFundAccount);
        $this->assertTrue($caps->requiresIpAllowlisting);
        $this->assertSame([PayoutMethodType::BankTransfer], $caps->supportedDestinationTypes);
    }

    public function test_capabilities_health_is_unhealthy_when_unconfigured_and_never_calls_the_network(): void
    {
        $this->client->shouldNotReceive('fetchBalanceOrHealth');

        $caps = $this->provider()->capabilities();

        $this->assertFalse($caps->healthStatus->healthy);
    }

    public function test_capabilities_health_is_healthy_once_structurally_configured_without_calling_the_network(): void
    {
        $this->configure();
        $this->client->shouldNotReceive('fetchBalanceOrHealth');

        $caps = $this->provider()->capabilities();

        $this->assertTrue($caps->healthStatus->healthy);
    }

    public function test_supports_currency_is_inr_only(): void
    {
        $provider = $this->provider();

        $this->assertTrue($provider->supportsCurrency('INR'));
        $this->assertTrue($provider->supportsCurrency('inr'));
        $this->assertFalse($provider->supportsCurrency('USD'));
    }

    // ── validateDestination ─────────────────────────────────────────

    public function test_validate_destination_rejects_non_inr_currency(): void
    {
        $reason = $this->provider()->validateDestination($this->destinationSnapshot(['currency_code' => 'USD']));

        $this->assertNotNull($reason);
    }

    public function test_validate_destination_rejects_missing_account_number(): void
    {
        $reason = $this->provider()->validateDestination($this->destinationSnapshot(['account_number' => null]));

        $this->assertNotNull($reason);
    }

    public function test_validate_destination_rejects_missing_ifsc(): void
    {
        $reason = $this->provider()->validateDestination($this->destinationSnapshot(['routing_number' => null]));

        $this->assertNotNull($reason);
    }

    public function test_validate_destination_accepts_a_complete_snapshot(): void
    {
        $reason = $this->provider()->validateDestination($this->destinationSnapshot());

        $this->assertNull($reason);
    }

    // ── initiate() ───────────────────────────────────────────────────

    public function test_initiate_throws_when_razorpayx_is_disabled(): void
    {
        $this->configure(enabled: false);

        $this->expectException(PayoutProviderException::class);
        $this->provider()->initiate($this->initiationRequest());
    }

    public function test_initiate_throws_for_non_inr_currency(): void
    {
        $this->configure();

        $this->expectException(PayoutProviderException::class);
        $this->provider()->initiate($this->initiationRequest(['currencyCode' => 'USD']));
    }

    public function test_initiate_throws_when_no_ready_provider_link_exists(): void
    {
        $this->configure();

        $this->expectException(PayoutProviderException::class);
        $this->provider()->initiate($this->initiationRequest());
    }

    public function test_initiate_succeeds_and_sets_the_payout_idempotency_header_via_the_client(): void
    {
        $this->configure();
        $method = $this->readyPayoutMethod();

        $this->client->shouldReceive('createPayout')
            ->once()
            ->withArgs(fn ($request) => $request->idempotencyKey === 'idem-key-1' && $request->fundAccountId === 'fa_test123')
            ->andReturn(new RazorpayXPayoutResult(
                payoutId: 'pout_test123',
                status: 'processing',
                utr: null,
                feesMinor: null,
                taxMinor: null,
                mode: 'IMPS',
                referenceId: 'idem-key-1',
                failureReason: null,
            ));

        $result = $this->provider()->initiate($this->initiationRequest([], $method));

        $this->assertSame(InstructorPayoutAttemptStatus::Processing, $result->attemptStatus);
        $this->assertSame('pout_test123', $result->providerPayoutId);
    }

    public function test_initiate_converts_a_provider_exception_into_a_failure_result_instead_of_throwing(): void
    {
        $this->configure();
        $method = $this->readyPayoutMethod();

        $this->client->shouldReceive('createPayout')
            ->once()
            ->andThrow(new RazorpayXRequestException('Bad request', httpStatus: 400));

        $result = $this->provider()->initiate($this->initiationRequest([], $method));

        $this->assertSame(InstructorPayoutAttemptStatus::Failed, $result->attemptStatus);
        $this->assertNull($result->providerPayoutId);
    }

    public function test_initiate_treats_a_server_error_as_unknown_not_failed(): void
    {
        $this->configure();
        $method = $this->readyPayoutMethod();

        $this->client->shouldReceive('createPayout')
            ->once()
            ->andThrow(new RazorpayXRequestException('Timeout', httpStatus: 503));

        $result = $this->provider()->initiate($this->initiationRequest([], $method));

        $this->assertSame(InstructorPayoutAttemptStatus::Unknown, $result->attemptStatus);
        $this->assertSame(PayoutFailureCategory::ReconciliationRequired, $result->failureCategory);
    }

    // ── fetchStatus() ────────────────────────────────────────────────

    public function test_fetch_status_maps_a_successful_client_response(): void
    {
        $this->client->shouldReceive('fetchPayout')
            ->once()
            ->with('pout_test123')
            ->andReturn(new RazorpayXPayoutResult('pout_test123', 'processed', 'UTR1', 826, 126, 'IMPS', 'ref', null));

        $status = $this->provider()->fetchStatus('pout_test123');

        $this->assertSame(InstructorPayoutAttemptStatus::Succeeded, $status->attemptStatus);
    }

    public function test_fetch_status_returns_unknown_when_the_client_throws(): void
    {
        $this->client->shouldReceive('fetchPayout')
            ->once()
            ->andThrow(new RazorpayXRequestException('unreachable'));

        $status = $this->provider()->fetchStatus('pout_test123');

        $this->assertSame(InstructorPayoutAttemptStatus::Unknown, $status->attemptStatus);
        $this->assertSame(PayoutFailureCategory::ProviderUnavailable, $status->failureCategory);
    }

    // ── cancelWhenSupported() ────────────────────────────────────────

    public function test_cancel_when_supported_delegates_to_the_client(): void
    {
        $this->client->shouldReceive('cancelQueuedPayout')->once()->with('pout_test123')->andReturn(true);

        $this->assertTrue($this->provider()->cancelWhenSupported('pout_test123'));
    }

    public function test_cancel_when_supported_returns_false_on_client_exception(): void
    {
        $this->client->shouldReceive('cancelQueuedPayout')->once()->andThrow(new RazorpayXRequestException('nope'));

        $this->assertFalse($this->provider()->cancelWhenSupported('pout_test123'));
    }

    // ── normalizeEvent() (webhook signature) ────────────────────────

    private function webhookPayload(): string
    {
        return json_encode([
            'event' => 'payout.processed',
            'payload' => [
                'payout' => [
                    'entity' => [
                        'id' => 'pout_test123',
                        'status' => 'processed',
                        'amount' => 50000,
                        'currency' => 'INR',
                        'utr' => 'UTR1',
                        'mode' => 'IMPS',
                        'reference_id' => 'attempt-ref',
                        'fees' => 826,
                        'tax' => 126,
                    ],
                ],
            ],
            'created_at' => now()->timestamp,
        ], JSON_THROW_ON_ERROR);
    }

    private function signedRequest(string $payload, string $secret, string $eventId = 'evt_test123'): Request
    {
        return Request::create('/api/webhooks/payouts/razorpayx', 'POST', content: $payload, server: [
            'HTTP_X-Razorpay-Signature' => hash_hmac('sha256', $payload, $secret),
            'HTTP_x-razorpay-event-id' => $eventId,
        ]);
    }

    public function test_normalize_event_accepts_a_correctly_signed_payload(): void
    {
        $this->configure();
        $payload = $this->webhookPayload();

        $event = $this->provider()->normalizeEvent($this->signedRequest($payload, self::WEBHOOK_SECRET));

        $this->assertSame('razorpayx', $event->provider);
        $this->assertSame('evt_test123', $event->providerEventId);
        $this->assertSame('pout_test123', $event->providerPayoutId);
        $this->assertSame(InstructorPayoutAttemptStatus::Succeeded, $event->attemptStatus);
        $this->assertTrue($event->signatureValid);
    }

    public function test_normalize_event_rejects_an_incorrect_signature(): void
    {
        $this->configure();

        $this->expectException(PayoutProviderException::class);
        $this->provider()->normalizeEvent($this->signedRequest($this->webhookPayload(), 'wrong-secret'));
    }

    public function test_normalize_event_rejects_a_missing_signature(): void
    {
        $this->configure();
        $payload = $this->webhookPayload();

        $request = Request::create('/api/webhooks/payouts/razorpayx', 'POST', content: $payload, server: [
            'HTTP_x-razorpay-event-id' => 'evt_test123',
        ]);

        $this->expectException(PayoutProviderException::class);
        $this->provider()->normalizeEvent($request);
    }

    /** Fails closed: no webhook secret configured must never be treated as "unsigned OK". */
    public function test_normalize_event_fails_closed_when_no_secret_is_configured(): void
    {
        $this->configure();
        $settings = app(RazorpayXPayoutSettings::class);
        $settings->razorpayx_webhook_secret = null;
        $settings->save();

        $this->expectException(PayoutProviderException::class);
        $this->provider()->normalizeEvent($this->signedRequest($this->webhookPayload(), self::WEBHOOK_SECRET));
    }

    /** Rotation window: the previous secret is still accepted. */
    public function test_normalize_event_accepts_the_previous_secret_during_rotation(): void
    {
        $this->configure();
        $settings = app(RazorpayXPayoutSettings::class);
        $settings->razorpayx_previous_webhook_secret = Crypt::encryptString('old-secret');
        $settings->razorpayx_webhook_secret = Crypt::encryptString('new-secret');
        $settings->save();

        $event = $this->provider()->normalizeEvent($this->signedRequest($this->webhookPayload(), 'old-secret'));

        $this->assertSame('pout_test123', $event->providerPayoutId);
    }

    public function test_normalize_event_rejects_a_missing_event_id(): void
    {
        $this->configure();
        $payload = $this->webhookPayload();

        $request = Request::create('/api/webhooks/payouts/razorpayx', 'POST', content: $payload, server: [
            'HTTP_X-Razorpay-Signature' => hash_hmac('sha256', $payload, self::WEBHOOK_SECRET),
        ]);

        $this->expectException(PayoutProviderException::class);
        $this->provider()->normalizeEvent($request);
    }

    public function test_normalize_event_rejects_a_payload_missing_the_payout_entity(): void
    {
        $this->configure();
        $payload = json_encode(['event' => 'payout.processed', 'payload' => []], JSON_THROW_ON_ERROR);

        $this->expectException(PayoutProviderException::class);
        $this->provider()->normalizeEvent($this->signedRequest($payload, self::WEBHOOK_SECRET));
    }

    // ── healthCheck() ────────────────────────────────────────────────

    public function test_health_check_is_unhealthy_without_calling_the_client_when_unconfigured(): void
    {
        $this->client->shouldNotReceive('fetchBalanceOrHealth');

        $health = $this->provider()->healthCheck();

        $this->assertFalse($health->healthy);
    }

    public function test_health_check_delegates_to_the_client_once_configured(): void
    {
        $this->configure();
        $this->client->shouldReceive('fetchBalanceOrHealth')
            ->once()
            ->with('1234567890')
            ->andReturn(new RazorpayXHealthResult(healthy: true));

        $this->assertTrue($this->provider()->healthCheck()->healthy);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function destinationSnapshot(array $overrides = []): array
    {
        return array_merge([
            'payout_method_id' => (string) Str::uuid(),
            'payout_method_type' => PayoutMethodType::BankTransfer->value,
            'currency_code' => 'INR',
            'account_number' => '000123456789',
            'routing_number' => 'HDFC0000123',
            'account_holder_name' => 'Test Instructor',
        ], $overrides);
    }

    private function readyPayoutMethod(): InstructorPayoutMethod
    {
        $method = InstructorPayoutMethod::factory()->verified()->create(['currency_code' => 'INR']);

        InstructorPayoutDestinationProviderLink::factory()->ready()->create([
            'payout_method_id' => $method->id,
            'instructor_id' => $method->instructor_id,
            'provider_fund_account_id' => 'fa_test123',
        ]);

        return $method;
    }

    /** @param array<string, mixed> $overrides */
    private function initiationRequest(array $overrides = [], ?InstructorPayoutMethod $method = null): PayoutInitiationRequest
    {
        $snapshot = $this->destinationSnapshot($method !== null ? ['payout_method_id' => $method->id] : []);

        $defaults = [
            'attemptReference' => 'attempt-ref',
            'withdrawalReference' => 'withdrawal-ref',
            'amountMinor' => 50000,
            'currencyCode' => 'INR',
            'idempotencyKey' => 'idem-key-1',
            'destinationSnapshot' => $snapshot,
            'purpose' => 'payout',
        ];

        $merged = array_merge($defaults, $overrides);

        return new PayoutInitiationRequest(
            attemptReference: $merged['attemptReference'],
            withdrawalReference: $merged['withdrawalReference'],
            amountMinor: $merged['amountMinor'],
            currencyCode: $merged['currencyCode'],
            idempotencyKey: $merged['idempotencyKey'],
            destinationSnapshot: $merged['destinationSnapshot'],
            purpose: $merged['purpose'],
        );
    }
}
