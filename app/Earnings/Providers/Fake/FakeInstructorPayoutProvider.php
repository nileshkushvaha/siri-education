<?php

declare(strict_types=1);

namespace App\Earnings\Providers\Fake;

use App\Earnings\Contracts\InstructorPayoutProviderInterface;
use App\Earnings\DTOs\NormalizedPayoutEvent;
use App\Earnings\DTOs\PayoutInitiationRequest;
use App\Earnings\DTOs\PayoutInitiationResult;
use App\Earnings\DTOs\PayoutProviderHealth;
use App\Earnings\DTOs\PayoutStatusResult;
use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\PayoutFailureCategory;
use App\Earnings\Exceptions\PayoutProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic, network-free payout provider for local/testing use
 * (and, by explicit config, a staging simulation environment — see
 * InstructorPayoutProviderResolver). Every outcome is driven by the
 * caller-supplied `scenario` (test/dev only — see
 * PayoutInitiationRequest::$scenario); a real adapter has no such
 * field and would never branch on caller-supplied intent this way.
 * `success_immediate` is the deterministic default so any code path
 * that never sets a scenario behaves predictably, not randomly.
 */
final class FakeInstructorPayoutProvider implements InstructorPayoutProviderInterface
{
    public const string KEY = 'fake';

    /** @var list<string> */
    public const array SCENARIOS = [
        'success_immediate', 'success_async', 'failure_retryable', 'failure_permanent',
        'timeout_before_acceptance', 'timeout_after_acceptance', 'unknown',
        'reversed_after_success', 'queued', 'processing', 'duplicate_event',
    ];

    public function providerName(): string
    {
        return self::KEY;
    }

    public function supportsCurrency(string $currencyCode): bool
    {
        return in_array(strtoupper($currencyCode), ['INR', 'USD', 'EUR', 'GBP', 'AED'], true);
    }

    public function validateDestination(array $destinationSnapshot): ?string
    {
        if (blank($destinationSnapshot['masked_identifier'] ?? null)) {
            return 'The payout destination is missing an identifying detail.';
        }

        if (blank($destinationSnapshot['account_number'] ?? null) && blank($destinationSnapshot['iban'] ?? null)) {
            return 'The payout destination has neither an account number nor an IBAN.';
        }

        if (blank($destinationSnapshot['currency_code'] ?? null)) {
            return 'The payout destination is missing a currency.';
        }

        return null;
    }

    public function initiate(PayoutInitiationRequest $request): PayoutInitiationResult
    {
        $scenario = $request->scenario ?? 'success_immediate';

        if (! in_array($scenario, self::SCENARIOS, true)) {
            throw new PayoutProviderException(sprintf('Unknown fake provider scenario "%s".', $scenario));
        }

        $providerPayoutId = $this->stablePayoutId($request->idempotencyKey, $scenario);

        return match ($scenario) {
            'success_immediate', 'reversed_after_success' => new PayoutInitiationResult(
                InstructorPayoutAttemptStatus::Succeeded,
                $providerPayoutId,
                'paid',
                null,
                null,
                ['scenario' => $scenario],
            ),
            'success_async', 'processing' => new PayoutInitiationResult(
                InstructorPayoutAttemptStatus::Processing,
                $providerPayoutId,
                'processing',
                null,
                null,
                ['scenario' => $scenario],
            ),
            'queued' => new PayoutInitiationResult(
                InstructorPayoutAttemptStatus::Acknowledged,
                $providerPayoutId,
                'queued',
                null,
                null,
                ['scenario' => $scenario],
            ),
            'failure_retryable' => new PayoutInitiationResult(
                InstructorPayoutAttemptStatus::Failed,
                null,
                'rejected_retryable',
                'The provider reported a temporary problem and asked for a retry.',
                PayoutFailureCategory::ProviderRetryable,
            ),
            'failure_permanent' => new PayoutInitiationResult(
                InstructorPayoutAttemptStatus::Failed,
                $providerPayoutId,
                'rejected_permanent',
                'The provider permanently rejected this destination.',
                PayoutFailureCategory::ProviderPermanent,
            ),
            'timeout_before_acceptance' => new PayoutInitiationResult(
                InstructorPayoutAttemptStatus::Failed,
                null,
                null,
                'The request timed out before the provider confirmed receipt.',
                PayoutFailureCategory::ProviderTimeoutBeforeAcceptance,
            ),
            'timeout_after_acceptance' => new PayoutInitiationResult(
                InstructorPayoutAttemptStatus::Unknown,
                $providerPayoutId,
                'unknown',
                'The request timed out after the provider acknowledged it; the outcome is unconfirmed.',
                PayoutFailureCategory::ProviderTimeoutUnknownAcceptance,
                ['scenario' => $scenario],
            ),
            'unknown' => new PayoutInitiationResult(
                InstructorPayoutAttemptStatus::Unknown,
                $providerPayoutId,
                'unknown',
                'The provider outcome could not be determined.',
                PayoutFailureCategory::ReconciliationRequired,
                ['scenario' => $scenario],
            ),
            'duplicate_event' => new PayoutInitiationResult(
                InstructorPayoutAttemptStatus::Acknowledged,
                $providerPayoutId,
                'queued',
                null,
                null,
                ['scenario' => $scenario],
            ),
            default => throw new PayoutProviderException(sprintf('Unhandled fake provider scenario "%s".', $scenario)),
        };
    }

    public function fetchStatus(string $providerPayoutId): PayoutStatusResult
    {
        $scenario = $this->scenarioForPayoutId($providerPayoutId);

        return match ($scenario) {
            'reversed_after_success' => new PayoutStatusResult(
                InstructorPayoutAttemptStatus::Reversed,
                $providerPayoutId,
                'reversed',
                'The provider reported the funds were returned by the receiving bank.',
                null,
                CarbonImmutable::now(),
            ),
            'success_async' => new PayoutStatusResult(
                InstructorPayoutAttemptStatus::Succeeded,
                $providerPayoutId,
                'paid',
                null,
                null,
                CarbonImmutable::now(),
            ),
            'timeout_after_acceptance', 'unknown' => new PayoutStatusResult(
                InstructorPayoutAttemptStatus::Unknown,
                $providerPayoutId,
                'unknown',
                'The provider outcome is still unconfirmed.',
                PayoutFailureCategory::ReconciliationRequired,
                CarbonImmutable::now(),
            ),
            default => new PayoutStatusResult(
                InstructorPayoutAttemptStatus::Processing,
                $providerPayoutId,
                'processing',
                null,
                null,
                CarbonImmutable::now(),
            ),
        };
    }

    /** The fake provider always accepts a pre-acceptance cancel. */
    public function cancelWhenSupported(string $providerPayoutId): bool
    {
        return true;
    }

    public function normalizeEvent(Request $request): NormalizedPayoutEvent
    {
        $signature = (string) $request->header('X-Payout-Fake-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), (string) config('app.key'));

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new PayoutProviderException('Fake payout event signature verification failed.');
        }

        $status = InstructorPayoutAttemptStatus::tryFrom((string) $request->json('status'));
        $providerEventId = (string) $request->json('provider_event_id');
        $providerPayoutId = $request->json('provider_payout_id');

        if ($status === null || $providerEventId === '') {
            throw new PayoutProviderException('Fake payout event payload is malformed.');
        }

        $payload = (string) $request->getContent();

        return new NormalizedPayoutEvent(
            provider: self::KEY,
            providerEventId: $providerEventId,
            eventType: (string) $request->json('event_type', 'status_changed'),
            providerPayoutId: $providerPayoutId !== null ? (string) $providerPayoutId : null,
            attemptStatus: $status,
            amountMinor: $request->json('amount_minor') !== null ? (int) $request->json('amount_minor') : null,
            currencyCode: $request->json('currency_code') !== null ? (string) $request->json('currency_code') : null,
            occurredAt: CarbonImmutable::now(),
            payloadHash: hash('sha256', $payload),
            signatureValid: true,
        );
    }

    public function healthCheck(): PayoutProviderHealth
    {
        Log::debug('FakeInstructorPayoutProvider: health check (no network call).');

        return new PayoutProviderHealth(healthy: true, safeMessage: 'Fake provider — always healthy, no network dependency.');
    }

    /**
     * The scenario is embedded in the (otherwise opaque, fake-only)
     * provider payout ID itself, so fetchStatus() — called later,
     * possibly by a different process (the reconciliation sweep) — can
     * recover it without any shared state. This is safe only because
     * every byte here is synthetic test data; a real adapter's payout
     * ID is provider-assigned and carries no such meaning.
     */
    private function stablePayoutId(string $idempotencyKey, string $scenario): string
    {
        $hash = substr(hash_hmac('sha256', $idempotencyKey, (string) config('app.key')), 0, 24);

        return sprintf('fake_%s_%s', $scenario, $hash);
    }

    private function scenarioForPayoutId(string $providerPayoutId): string
    {
        if (preg_match('/^fake_([a-z_]+)_[0-9a-f]{24}$/', $providerPayoutId, $matches) === 1) {
            return $matches[1];
        }

        return 'processing';
    }
}
