<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\RazorpayX;

use App\Earnings\Contracts\InstructorPayoutExecutionServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Enums\InstructorStatus;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutAttempt;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorPayoutProviderEvent;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Settings\RazorpayXPayoutSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\ManagesFinancialSettings;
use Tests\TestCase;

/**
 * Feature test for `POST /api/webhooks/payouts/razorpayx` — proves the
 * real route/controller/adapter wiring, on top of the signature and
 * normalization unit coverage in RazorpayXInstructorPayoutProviderTest
 * and the generic event-processing coverage already proven for the
 * fake provider in PayoutExecutionTest.
 */
class RazorpayXPayoutWebhookTest extends TestCase
{
    use ManagesFinancialSettings;
    use RefreshDatabase;

    private const string WEBHOOK_SECRET = 'razorpayx-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->setFinancialSettings([
            'earnings_enabled' => true,
            'withdrawals_enabled' => true,
            'withdrawal_review_required' => false,
            'minimum_withdrawal_minor' => 1000,
            'maximum_active_requests_per_instructor' => 5,
            'payout_execution_enabled' => true,
            'payout_provider' => 'fake',
            'payout_maker_checker_enabled' => true,
            'payout_auto_retry_enabled' => false,
        ]);

        $settings = app(RazorpayXPayoutSettings::class);
        $settings->razorpayx_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $settings->save();
    }

    /** A legitimately valid processing-state attempt+withdrawal+allocation chain (built via the fake provider), then relabeled as RazorpayX's — isolates webhook wiring from RazorpayX initiation itself, already covered in RazorpayXInstructorPayoutProviderTest. */
    private function razorpayXAttemptInFlight(int $amountMinor = 20000): InstructorPayoutAttempt
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile->update(['instructor_status' => InstructorStatus::Active]);

        $method = InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
        ]);

        InstructorEarning::factory()->releasable()->create([
            'instructor_id' => $instructor->id,
            'earning_amount_minor' => $amountMinor,
            'currency_code' => 'INR',
            'status' => InstructorEarningStatus::Releasable,
        ]);

        $withdrawal = app(InstructorWithdrawalServiceInterface::class)->requestWithdrawal(
            $instructor, $method, $amountMinor, null, (string) Str::uuid(),
        );
        app(InstructorWithdrawalServiceInterface::class)->approve($withdrawal, User::factory()->create(['status' => User::STATUS_ACTIVE]));

        $originalConnection = config('queue.default');
        config(['queue.default' => 'database']);
        $attempt = app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($withdrawal, User::factory()->create(['status' => User::STATUS_ACTIVE]));
        config(['queue.default' => $originalConnection]);

        InstructorPayoutAttempt::query()->whereKey($attempt->id)->update(['requested_fake_scenario' => 'success_async']);
        app(InstructorPayoutExecutionServiceInterface::class)->execute($attempt->fresh());

        // Relabel as RazorpayX's own attempt/payout id — this is the
        // only bypass in this fixture; everything above it went through
        // the real service layer.
        InstructorPayoutAttempt::query()->whereKey($attempt->id)->update([
            'provider' => 'razorpayx',
            'provider_payout_id' => 'pout_test123',
        ]);

        return $attempt->fresh();
    }

    private function webhookPayload(string $status, int $amountMinor, ?string $utr = null, string $providerPayoutId = 'pout_test123'): string
    {
        return json_encode([
            'event' => "payout.{$status}",
            'payload' => [
                'payout' => [
                    'entity' => [
                        'id' => $providerPayoutId,
                        'status' => $status,
                        'amount' => $amountMinor,
                        'currency' => 'INR',
                        'utr' => $utr,
                        'mode' => 'IMPS',
                        'reference_id' => 'attempt-ref',
                    ],
                ],
            ],
            'created_at' => now()->timestamp,
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function signedHeaders(string $payload, string $eventId, string $secret = self::WEBHOOK_SECRET): array
    {
        return [$payload, [
            'X-Razorpay-Signature' => hash_hmac('sha256', $payload, $secret),
            'x-razorpay-event-id' => $eventId,
        ]];
    }

    public function test_a_processed_webhook_marks_the_attempt_succeeded_and_the_withdrawal_paid(): void
    {
        $attempt = $this->razorpayXAttemptInFlight(20000);
        [$payload, $headers] = $this->signedHeaders($this->webhookPayload('processed', 20000, 'UTR12345'), 'evt-processed-1');

        $response = $this->call('POST', '/api/webhooks/payouts/razorpayx', server: $this->headersToServer($headers), content: $payload);

        $response->assertOk();
        $attempt->refresh();
        $this->assertSame(InstructorPayoutAttemptStatus::Succeeded, $attempt->status);

        $withdrawal = InstructorWithdrawalRequest::query()->whereKey($attempt->withdrawal_request_id)->sole();
        $this->assertSame(InstructorWithdrawalStatus::Paid, $withdrawal->status);
    }

    public function test_an_invalid_signature_is_rejected_and_never_mutates_the_attempt(): void
    {
        $attempt = $this->razorpayXAttemptInFlight(20000);
        $payload = $this->webhookPayload('processed', 20000);

        $response = $this->call('POST', '/api/webhooks/payouts/razorpayx', server: $this->headersToServer([
            'X-Razorpay-Signature' => hash_hmac('sha256', $payload, 'wrong-secret'),
            'x-razorpay-event-id' => 'evt-bad-sig',
        ]), content: $payload);

        $response->assertStatus(401);
        $attempt->refresh();
        $this->assertNotSame(InstructorPayoutAttemptStatus::Succeeded, $attempt->status);
        $this->assertSame(0, InstructorPayoutProviderEvent::query()->where('provider_event_id', 'evt-bad-sig')->count());
    }

    public function test_a_duplicate_event_id_has_no_additional_financial_effect(): void
    {
        $this->razorpayXAttemptInFlight(20000);
        [$payload, $headers] = $this->signedHeaders($this->webhookPayload('processed', 20000, 'UTR1'), 'evt-dup-1');

        $this->call('POST', '/api/webhooks/payouts/razorpayx', server: $this->headersToServer($headers), content: $payload)->assertOk();
        $this->call('POST', '/api/webhooks/payouts/razorpayx', server: $this->headersToServer($headers), content: $payload)->assertOk();

        $this->assertSame(1, InstructorPayoutProviderEvent::query()->where('provider_event_id', 'evt-dup-1')->count());
        $this->assertSame(1, InstructorPayoutProviderEvent::query()->where('provider_event_id', 'like', 'evt-dup-1:dup:%')->count());
    }

    /** processed is terminal — a stale, later-arriving `pending` event must never downgrade it. */
    public function test_a_stale_pending_event_after_processed_does_not_downgrade_the_attempt(): void
    {
        $attempt = $this->razorpayXAttemptInFlight(20000);

        [$processedPayload, $processedHeaders] = $this->signedHeaders($this->webhookPayload('processed', 20000, 'UTR1'), 'evt-processed-2');
        $this->call('POST', '/api/webhooks/payouts/razorpayx', server: $this->headersToServer($processedHeaders), content: $processedPayload)->assertOk();

        [$stalePayload, $staleHeaders] = $this->signedHeaders($this->webhookPayload('pending', 20000), 'evt-stale-pending');
        $this->call('POST', '/api/webhooks/payouts/razorpayx', server: $this->headersToServer($staleHeaders), content: $stalePayload)->assertOk();

        $attempt->refresh();
        $this->assertSame(InstructorPayoutAttemptStatus::Succeeded, $attempt->status);
    }

    public function test_the_previous_webhook_secret_is_accepted_during_a_rotation_window(): void
    {
        $attempt = $this->razorpayXAttemptInFlight(20000);

        $settings = app(RazorpayXPayoutSettings::class);
        $settings->razorpayx_previous_webhook_secret = Crypt::encryptString('old-secret');
        $settings->razorpayx_webhook_secret = Crypt::encryptString('new-secret');
        $settings->save();

        [$payload, $headers] = $this->signedHeaders($this->webhookPayload('processed', 20000, 'UTR1'), 'evt-rotation-1', 'old-secret');

        $this->call('POST', '/api/webhooks/payouts/razorpayx', server: $this->headersToServer($headers), content: $payload)->assertOk();

        $attempt->refresh();
        $this->assertSame(InstructorPayoutAttemptStatus::Succeeded, $attempt->status);
    }

    /** @param array<string, string> $headers @return array<string, string> */
    private function headersToServer(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
