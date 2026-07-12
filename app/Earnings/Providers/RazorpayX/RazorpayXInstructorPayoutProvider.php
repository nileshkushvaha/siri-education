<?php

declare(strict_types=1);

namespace App\Earnings\Providers\RazorpayX;

use App\Earnings\Contracts\InstructorPayoutProviderInterface;
use App\Earnings\DTOs\NormalizedPayoutEvent;
use App\Earnings\DTOs\PayoutInitiationRequest;
use App\Earnings\DTOs\PayoutInitiationResult;
use App\Earnings\DTOs\PayoutProviderCapabilities;
use App\Earnings\DTOs\PayoutProviderHealth;
use App\Earnings\DTOs\PayoutStatusResult;
use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\PayoutFailureCategory;
use App\Earnings\Enums\PayoutMethodType;
use App\Earnings\Exceptions\PayoutProviderException;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXPayoutRequest;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXPayoutResult;
use App\Models\InstructorPayoutDestinationProviderLink;
use App\Settings\RazorpayXPayoutSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * The RazorpayX India/INR instructor payout provider (Phase 16B).
 * Registered alongside the fake provider — never replaces it — and
 * selected only when routing/eligibility (InstructorPayoutEligibilityService)
 * resolves to `razorpayx`, itself gated by
 * InstructorEarningSettings::payout_execution_enabled AND
 * RazorpayXPayoutSettings::razorpayx_enabled. Every network call goes
 * through RazorpayXPayoutClientInterface only; this class never issues
 * an HTTP request directly.
 */
final class RazorpayXInstructorPayoutProvider implements InstructorPayoutProviderInterface
{
    public const string KEY = 'razorpayx';

    public function __construct(
        private readonly RazorpayXPayoutClientInterface $client,
        private readonly RazorpayXPayoutSettings $settings,
        private readonly RazorpayXPayoutConfigurationValidator $configValidator,
        private readonly RazorpayXStatusMapper $statusMapper,
    ) {}

    public function providerName(): string
    {
        return self::KEY;
    }

    public function supportsCurrency(string $currencyCode): bool
    {
        return strtoupper($currencyCode) === 'INR';
    }

    /** @param array<string, mixed> $destinationSnapshot */
    public function validateDestination(array $destinationSnapshot): ?string
    {
        if (($destinationSnapshot['payout_method_type'] ?? null) !== PayoutMethodType::BankTransfer->value) {
            return 'RazorpayX only supports bank transfer destinations.';
        }

        if (strtoupper((string) ($destinationSnapshot['currency_code'] ?? '')) !== 'INR') {
            return 'RazorpayX only supports INR payouts.';
        }

        if (blank($destinationSnapshot['account_number'] ?? null)) {
            return 'The payout destination is missing a bank account number.';
        }

        if (blank($destinationSnapshot['routing_number'] ?? null)) {
            return 'The payout destination is missing an IFSC code.';
        }

        if (blank($destinationSnapshot['account_holder_name'] ?? null)) {
            return 'The payout destination is missing an account holder name.';
        }

        if (blank($destinationSnapshot['payout_method_id'] ?? null)) {
            return 'The payout destination is missing its payout method reference.';
        }

        return null;
    }

    public function initiate(PayoutInitiationRequest $request): PayoutInitiationResult
    {
        if (! $this->settings->razorpayx_enabled) {
            throw new PayoutProviderException('RazorpayX is not enabled.');
        }

        if (strtoupper($request->currencyCode) !== 'INR') {
            throw new PayoutProviderException('RazorpayX only supports INR payouts.');
        }

        $payoutMethodId = $request->destinationSnapshot['payout_method_id'] ?? null;

        if (! is_string($payoutMethodId) || $payoutMethodId === '') {
            throw new PayoutProviderException('The payout destination snapshot is missing its payout method reference.');
        }

        $link = InstructorPayoutDestinationProviderLink::query()
            ->where('payout_method_id', $payoutMethodId)
            ->where('provider', self::KEY)
            ->first();

        if ($link === null || ! $link->isReadyForPayout()) {
            throw new PayoutProviderException('This destination is not yet provisioned and ready with RazorpayX.');
        }

        if (blank($this->settings->razorpayx_account_number)) {
            throw new PayoutProviderException('RazorpayX source account number is not configured.');
        }

        $payoutRequest = new RazorpayXPayoutRequest(
            accountNumber: (string) $this->settings->razorpayx_account_number,
            fundAccountId: (string) $link->provider_fund_account_id,
            amountMinor: $request->amountMinor,
            mode: $this->settings->razorpayx_default_mode,
            purpose: filled($request->purpose) ? $request->purpose : $this->settings->razorpayx_default_purpose,
            referenceId: $request->attemptReference,
            narration: $request->safeNotes ?? 'Instructor payout',
            idempotencyKey: $request->idempotencyKey,
            queueIfLowBalance: $this->settings->razorpayx_queue_if_low_balance,
        );

        try {
            $result = $this->client->createPayout($payoutRequest);
        } catch (RazorpayXRequestException $e) {
            return $this->initiationFailureFromException($e);
        }

        return $this->statusMapper->toInitiationResult($result);
    }

    public function fetchStatus(string $providerPayoutId): PayoutStatusResult
    {
        try {
            $result = $this->client->fetchPayout($providerPayoutId);
        } catch (RazorpayXRequestException) {
            return new PayoutStatusResult(
                attemptStatus: InstructorPayoutAttemptStatus::Unknown,
                providerPayoutId: $providerPayoutId,
                providerStatus: null,
                safeReason: 'The payout provider could not be reached to confirm this payout\'s status.',
                failureCategory: PayoutFailureCategory::ProviderUnavailable,
                providerOccurredAt: CarbonImmutable::now(),
            );
        }

        return $this->statusMapper->toStatusResult($result);
    }

    /** RazorpayX only ever accepts cancellation while a payout is still queued (pre-processing). */
    public function cancelWhenSupported(string $providerPayoutId): bool
    {
        try {
            return $this->client->cancelQueuedPayout($providerPayoutId);
        } catch (RazorpayXRequestException) {
            return false;
        }
    }

    public function normalizeEvent(Request $request): NormalizedPayoutEvent
    {
        $payload = (string) $request->getContent();

        if (! $this->verifySignature($payload, (string) $request->header('X-Razorpay-Signature', ''))) {
            throw new PayoutProviderException('RazorpayX webhook signature verification failed.');
        }

        $eventId = (string) $request->header('x-razorpay-event-id', '');

        if ($eventId === '') {
            throw new PayoutProviderException('RazorpayX webhook is missing its event id.');
        }

        $data = json_decode($payload, true);
        $payoutEntity = is_array($data) ? ($data['payload']['payout']['entity'] ?? null) : null;

        if (! is_array($payoutEntity) || ! isset($payoutEntity['id'], $payoutEntity['status'])) {
            throw new PayoutProviderException('RazorpayX webhook payload is missing the payout entity.');
        }

        $result = new RazorpayXPayoutResult(
            payoutId: (string) $payoutEntity['id'],
            status: (string) $payoutEntity['status'],
            utr: $payoutEntity['utr'] ?? null,
            feesMinor: isset($payoutEntity['fees']) ? (int) $payoutEntity['fees'] : null,
            taxMinor: isset($payoutEntity['tax']) ? (int) $payoutEntity['tax'] : null,
            mode: (string) ($payoutEntity['mode'] ?? ''),
            referenceId: $payoutEntity['reference_id'] ?? null,
            failureReason: $payoutEntity['failure_reason'] ?? null,
        );

        [$attemptStatus] = $this->statusMapper->classify($result->status, $result->failureReason);
        $eventType = is_array($data) ? (string) ($data['event'] ?? '') : '';

        return new NormalizedPayoutEvent(
            provider: self::KEY,
            providerEventId: $eventId,
            eventType: $eventType !== '' ? $eventType : 'payout.'.$result->status,
            providerPayoutId: $result->payoutId,
            attemptStatus: $attemptStatus,
            amountMinor: isset($payoutEntity['amount']) ? (int) $payoutEntity['amount'] : null,
            currencyCode: isset($payoutEntity['currency']) ? (string) $payoutEntity['currency'] : null,
            occurredAt: isset($data['created_at']) ? CarbonImmutable::createFromTimestamp((int) $data['created_at']) : CarbonImmutable::now(),
            payloadHash: hash('sha256', $payload),
            signatureValid: true,
        );
    }

    /** Never a genuinely free network probe — a real, lightweight RazorpayX call. Deliberately NOT invoked from capabilities() (see its docblock). */
    public function healthCheck(): PayoutProviderHealth
    {
        if (! $this->isStructurallyConfigured()) {
            return new PayoutProviderHealth(healthy: false, safeMessage: 'RazorpayX is not enabled or its configuration is incomplete.');
        }

        $health = $this->client->fetchBalanceOrHealth((string) $this->settings->razorpayx_account_number);

        return new PayoutProviderHealth(healthy: $health->healthy, safeMessage: $health->safeMessage);
    }

    /**
     * Deliberately uses only the cheap structural check, never
     * healthCheck()'s live network probe — capabilities() is read on
     * every eligibility resolution (InstructorPayoutEligibilityService),
     * and a live RazorpayX call on that hot path would be both slow and
     * unnecessary network load. Mirrors
     * Booking\Payments\RazorpayPaymentProvider::capabilities().
     */
    public function capabilities(): PayoutProviderCapabilities
    {
        return new PayoutProviderCapabilities(
            provider: self::KEY,
            environment: $this->settings->razorpayx_environment,
            supportedInstructorCountries: ['IN'],
            supportedDestinationCountries: ['IN'],
            supportedCurrencies: ['INR'],
            supportedDestinationTypes: [PayoutMethodType::BankTransfer],
            supportedTransferModes: ['IMPS', 'NEFT', 'RTGS'],
            supportsStatusFetch: true,
            supportsWebhooks: true,
            supportsCancellation: true,
            supportsReversalEvents: true,
            supportsIdempotency: true,
            requiresContact: true,
            requiresFundAccount: true,
            requiresIpAllowlisting: true,
            healthStatus: $this->isStructurallyConfigured()
                ? new PayoutProviderHealth(healthy: true)
                : new PayoutProviderHealth(healthy: false, safeMessage: 'RazorpayX is not enabled or its configuration is incomplete.'),
            capabilityVersion: 1,
        );
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function isStructurallyConfigured(): bool
    {
        return $this->settings->razorpayx_enabled && $this->configValidator->isStructurallyValid($this->settings);
    }

    private function initiationFailureFromException(RazorpayXRequestException $e): PayoutInitiationResult
    {
        $result = new RazorpayXPayoutResult(
            payoutId: '',
            status: $e->httpStatus !== null && $e->httpStatus >= 500 ? 'unknown' : 'rejected',
            utr: null,
            feesMinor: null,
            taxMinor: null,
            mode: '',
            referenceId: null,
            failureReason: $e->getMessage(),
        );

        $mapped = $this->statusMapper->toInitiationResult($result);

        return new PayoutInitiationResult(
            attemptStatus: $mapped->attemptStatus,
            providerPayoutId: null,
            providerStatus: $mapped->providerStatus,
            safeReason: $mapped->safeReason,
            failureCategory: $mapped->failureCategory,
            safeMetadata: $mapped->safeMetadata,
        );
    }

    /** Constant-time; supports the current secret and, during a rotation window, the previous one. Fails closed when no secret is configured. */
    private function verifySignature(string $payload, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        foreach ([$this->settings->razorpayx_webhook_secret, $this->settings->razorpayx_previous_webhook_secret] as $secret) {
            $decrypted = $this->decryptSecret($secret);

            if ($decrypted === null) {
                continue;
            }

            if (hash_equals(hash_hmac('sha256', $payload, $decrypted), $signature)) {
                return true;
            }
        }

        return false;
    }

    private function decryptSecret(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return Str::startsWith($value, 'eyJpdiI6') ? null : $value;
        }
    }
}
