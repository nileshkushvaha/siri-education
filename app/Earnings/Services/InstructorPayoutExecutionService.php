<?php

declare(strict_types=1);

namespace App\Earnings\Services;

use App\Earnings\Contracts\InstructorPayoutExecutionServiceInterface;
use App\Earnings\Contracts\InstructorPayoutProviderResolverInterface;
use App\Earnings\Contracts\InstructorPayoutReconciliationServiceInterface;
use App\Earnings\Contracts\PayoutRequestFingerprintServiceInterface;
use App\Earnings\DTOs\NormalizedPayoutEvent;
use App\Earnings\DTOs\PayoutInitiationRequest;
use App\Earnings\DTOs\PayoutStatusResult;
use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\PayoutFailureCategory;
use App\Earnings\Enums\PayoutReconciliationIssueType;
use App\Earnings\Enums\PayoutReconciliationSeverity;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Events\InstructorPayoutFailed;
use App\Earnings\Events\InstructorPayoutOutcomeUnknown;
use App\Earnings\Events\InstructorPayoutProcessing;
use App\Earnings\Events\InstructorPayoutQueued;
use App\Earnings\Events\InstructorPayoutReversed;
use App\Earnings\Events\InstructorPayoutSucceeded;
use App\Earnings\Exceptions\InvalidPayoutAttemptTransitionException;
use App\Earnings\Exceptions\PayoutExecutionException;
use App\Earnings\Exceptions\PayoutProviderException;
use App\Earnings\Jobs\InitiateInstructorPayout;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutAttempt;
use App\Models\InstructorPayoutProviderEvent;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\InstructorEarningSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of payout-attempt state and the only caller allowed
 * to move a withdrawal through approved -> processing -> paid/failed/
 * reversed. See docs/financial-domain-architecture.md §16 for the full
 * lifecycle and lock-order documentation. Three finalize* methods each
 * lock the canonical rows in the documented order (user -> withdrawal
 * -> attempt -> allocations -> earnings -> reconciliation issue) and
 * apply exactly one financial effect; every caller (job, reconciliation
 * sweep, event handler, manual retry) funnels through them so the
 * effect can never be applied twice.
 */
final class InstructorPayoutExecutionService implements InstructorPayoutExecutionServiceInterface
{
    private const string LOG_NAME = 'instructor_payouts';

    private const string INTERNAL_LOG_NAME = 'instructor_payout_execution';

    private const string PURPOSE = 'instructor_withdrawal_payout';

    public function __construct(
        private readonly InstructorPayoutProviderResolverInterface $resolver,
        private readonly PayoutRequestFingerprintServiceInterface $fingerprints,
        private readonly InstructorEarningSettings $settings,
        private readonly AuditTrailService $audit,
    ) {}

    // ── Queueing ──────────────────────────────────────────────────────

    public function queueExecution(InstructorWithdrawalRequest $withdrawal, User $actor): InstructorPayoutAttempt
    {
        if (! $this->settings->payout_execution_enabled) {
            throw new PayoutExecutionException('Payout execution is currently disabled.');
        }

        $attempt = DB::transaction(function () use ($withdrawal, $actor): InstructorPayoutAttempt {
            // Canonical financial scope lock — see §31 lock order.
            User::query()->whereKey($withdrawal->instructor_id)->lockForUpdate()->first();

            $withdrawal = InstructorWithdrawalRequest::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if (! $withdrawal->status->isExecutable()) {
                throw new PayoutExecutionException('Only an approved withdrawal request can be queued for payout execution.');
            }

            if ($this->settings->payout_maker_checker_enabled && $withdrawal->approved_by === $actor->id) {
                throw new PayoutExecutionException('The withdrawal approver cannot also execute its payout while maker-checker is enabled.');
            }

            $allocations = $withdrawal->allocations()->where('status', WithdrawalAllocationStatus::Reserved)->lockForUpdate()->get();

            if ((int) $allocations->sum('amount_minor') !== $withdrawal->amount_minor) {
                throw new PayoutExecutionException('Reservation integrity check failed — the reserved amount no longer matches this withdrawal.');
            }

            try {
                $snapshot = $withdrawal->encrypted_payout_method_snapshot;
            } catch (DecryptException) {
                throw new PayoutExecutionException('The payout snapshot cannot be decrypted. Verify the application encryption key.');
            }

            if (empty($snapshot)) {
                throw new PayoutExecutionException('The payout snapshot is missing — this withdrawal cannot be executed.');
            }

            $provider = $this->resolver->resolve($this->settings->payout_provider, $withdrawal->currency_code);

            if (($reason = $provider->validateDestination($snapshot)) !== null) {
                throw new PayoutExecutionException('Destination validation failed: '.$reason);
            }

            $sequence = ((int) InstructorPayoutAttempt::query()->forWithdrawal($withdrawal->id)->max('execution_sequence')) + 1;
            $idempotencyKey = (string) Str::uuid();
            $fingerprint = $this->fingerprints->generate($withdrawal, $sequence, $provider->providerName(), $snapshot, self::PURPOSE);

            $attempt = InstructorPayoutAttempt::query()->create([
                'reference' => $this->generateAttemptReference(),
                'withdrawal_request_id' => $withdrawal->id,
                'instructor_id' => $withdrawal->instructor_id,
                'provider' => $provider->providerName(),
                'execution_sequence' => $sequence,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'amount_minor' => $withdrawal->amount_minor,
                'currency_id' => $withdrawal->currency_id,
                'currency_code' => $withdrawal->currency_code,
                'initiated_by' => $actor->id,
            ]);
            $attempt->forceFill(['status' => InstructorPayoutAttemptStatus::Created])->save();

            $withdrawal->forceFill([
                'status' => InstructorWithdrawalStatus::Processing,
                'processing_at' => now(),
            ])->save();

            return $attempt;
        });

        $this->audit->logUser($actor, self::LOG_NAME, 'withdrawal_processing_started', sprintf('Withdrawal %s queued for payout execution (attempt %s).', $withdrawal->reference, $attempt->reference), $withdrawal, [
            'attempt_reference' => $attempt->reference,
            'provider' => $attempt->provider,
        ]);
        $this->audit->logUser($actor, self::INTERNAL_LOG_NAME, 'payout_attempt_queued', sprintf('Payout attempt %s created for withdrawal %s.', $attempt->reference, $withdrawal->reference), $attempt);

        InstructorPayoutQueued::dispatch($attempt);
        InitiateInstructorPayout::dispatch($attempt->id);

        return $attempt;
    }

    // ── Execution (job-only) ─────────────────────────────────────────

    public function execute(InstructorPayoutAttempt $attempt): InstructorPayoutAttempt
    {
        $attempt = InstructorPayoutAttempt::query()->whereKey($attempt->id)->firstOrFail();

        if ($attempt->status->isTerminal() || $attempt->status === InstructorPayoutAttemptStatus::Succeeded) {
            // A duplicate job run (or a job that raced a reconciliation
            // resolution) is a no-op — never a second provider call.
            return $attempt;
        }

        $claimed = DB::transaction(function () use ($attempt): ?InstructorPayoutAttempt {
            $locked = InstructorPayoutAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal() || $locked->status === InstructorPayoutAttemptStatus::Succeeded) {
                return null;
            }

            if ($locked->status === InstructorPayoutAttemptStatus::Created) {
                $this->transitionAttempt($locked, InstructorPayoutAttemptStatus::Dispatching);
            }

            return $locked;
        });

        if ($claimed === null) {
            return InstructorPayoutAttempt::query()->whereKey($attempt->id)->firstOrFail();
        }

        $withdrawal = $claimed->withdrawalRequest;
        $snapshot = $withdrawal->encrypted_payout_method_snapshot;

        $request = new PayoutInitiationRequest(
            attemptReference: $claimed->reference,
            withdrawalReference: $withdrawal->reference,
            amountMinor: $claimed->amount_minor,
            currencyCode: $claimed->currency_code,
            idempotencyKey: $claimed->idempotency_key,
            destinationSnapshot: $snapshot,
            purpose: self::PURPOSE,
            scenario: $claimed->requested_fake_scenario,
        );

        $this->audit->logSystem(self::INTERNAL_LOG_NAME, 'payout_provider_call_started', sprintf('Provider call started for payout attempt %s.', $claimed->reference), $claimed, ['provider' => $claimed->provider]);

        try {
            // Deliberately outside any open transaction — see §18/§31.
            $provider = $this->resolver->resolve($claimed->provider, $claimed->currency_code);
            $result = $provider->initiate($request);
        } catch (PayoutProviderException $e) {
            return $this->persistProviderOutcome(
                $claimed->id,
                InstructorPayoutAttemptStatus::Failed,
                null,
                null,
                $e->getMessage(),
                PayoutFailureCategory::ConfigurationError,
                [],
            );
        }

        return $this->persistProviderOutcome(
            $claimed->id,
            $result->attemptStatus,
            $result->providerPayoutId,
            $result->providerStatus,
            $result->safeReason,
            $result->failureCategory,
            $result->safeMetadata,
        );
    }

    public function applyProviderStatus(InstructorPayoutAttempt $attempt, PayoutStatusResult $status): InstructorPayoutAttempt
    {
        return $this->persistProviderOutcome(
            $attempt->id,
            $status->attemptStatus,
            $status->providerPayoutId,
            $status->providerStatus,
            $status->safeReason,
            $status->failureCategory,
            [],
        );
    }

    public function handleNormalizedEvent(NormalizedPayoutEvent $event): void
    {
        $existing = InstructorPayoutProviderEvent::query()
            ->where('provider', $event->provider)
            ->where('provider_event_id', $event->providerEventId)
            ->first();

        if ($existing !== null) {
            $this->recordDuplicateEvent($event, $existing);

            return;
        }

        try {
            $eventRow = InstructorPayoutProviderEvent::query()->create([
                'provider' => $event->provider,
                'provider_event_id' => $event->providerEventId,
                'event_type' => $event->eventType,
                'provider_payout_id' => $event->providerPayoutId,
                'payload_hash' => $event->payloadHash,
                'signature_valid' => $event->signatureValid,
                'processing_status' => 'pending',
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent call won the race to record this exact event
            // (same provider + provider_event_id) — the DB unique
            // constraint is the backstop; treat this as the duplicate
            // rather than crash, so no financial effect is ever applied
            // twice regardless of how tight the race is.
            $existing = InstructorPayoutProviderEvent::query()
                ->where('provider', $event->provider)
                ->where('provider_event_id', $event->providerEventId)
                ->firstOrFail();
            $this->recordDuplicateEvent($event, $existing);

            return;
        }

        $attempt = $event->providerPayoutId !== null
            ? InstructorPayoutAttempt::query()->where('provider', $event->provider)->where('provider_payout_id', $event->providerPayoutId)->first()
            : null;

        if ($attempt === null) {
            $eventRow->forceFill(['processing_status' => 'invalid', 'processed_at' => now(), 'failure_reason' => 'No payout attempt matches this provider payout reference.'])->save();

            return;
        }

        if ($event->amountMinor !== null && $event->amountMinor !== $attempt->amount_minor) {
            $eventRow->forceFill(['processing_status' => 'invalid', 'processed_at' => now(), 'failure_reason' => 'Event amount does not match the attempt amount.'])->save();
            app(InstructorPayoutReconciliationServiceInterface::class)->raiseIssue($attempt, PayoutReconciliationIssueType::AmountMismatch, PayoutReconciliationSeverity::Critical, 'A provider event reported a mismatched amount.');

            return;
        }

        if ($event->currencyCode !== null && strtoupper($event->currencyCode) !== $attempt->currency_code) {
            $eventRow->forceFill(['processing_status' => 'invalid', 'processed_at' => now(), 'failure_reason' => 'Event currency does not match the attempt currency.'])->save();
            app(InstructorPayoutReconciliationServiceInterface::class)->raiseIssue($attempt, PayoutReconciliationIssueType::CurrencyMismatch, PayoutReconciliationSeverity::Critical, 'A provider event reported a mismatched currency.');

            return;
        }

        $this->persistProviderOutcome($attempt->id, $event->attemptStatus, $event->providerPayoutId, $event->eventType, null, null, []);

        $eventRow->forceFill(['processing_status' => 'processed', 'processed_at' => now()])->save();
    }

    private function recordDuplicateEvent(NormalizedPayoutEvent $event, InstructorPayoutProviderEvent $existing): void
    {
        // Idempotent — already processed (or ignored); no financial
        // effect is ever applied twice. A synthetic suffixed ID keeps
        // the duplicate visible in history without violating the
        // (provider, provider_event_id) uniqueness of the original.
        InstructorPayoutProviderEvent::query()->create([
            'provider' => $event->provider,
            'provider_event_id' => $event->providerEventId.':dup:'.Str::uuid(),
            'event_type' => $event->eventType,
            'provider_payout_id' => $event->providerPayoutId,
            'payload_hash' => $event->payloadHash,
            'signature_valid' => $event->signatureValid,
            'processing_status' => 'ignored',
            'received_at' => now(),
            'processed_at' => now(),
            'duplicate_of_id' => $existing->id,
            'failure_reason' => 'Duplicate of an already-received provider event.',
        ]);
    }

    // ── Manual actions ────────────────────────────────────────────────

    public function retry(InstructorWithdrawalRequest $withdrawal, User $actor, string $reason): InstructorPayoutAttempt
    {
        if (trim($reason) === '') {
            throw new PayoutExecutionException('A retry reason is required.');
        }

        $outcome = DB::transaction(function () use ($withdrawal, $actor, $reason): array {
            $withdrawal = InstructorWithdrawalRequest::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if ($withdrawal->status === InstructorWithdrawalStatus::Failed) {
                $allocations = $withdrawal->allocations()->where('status', WithdrawalAllocationStatus::Reserved)->lockForUpdate()->get();

                if ((int) $allocations->sum('amount_minor') !== $withdrawal->amount_minor) {
                    throw new PayoutExecutionException('This withdrawal\'s reservations were released after a permanent failure — ask the instructor to submit a new withdrawal instead.');
                }

                $withdrawal->forceFill([
                    'status' => InstructorWithdrawalStatus::Approved,
                    'recovered_at' => now(),
                    'recovered_by' => $actor->id,
                    'recovery_reason' => $reason,
                ])->save();

                $this->audit->logOverride($actor, self::LOG_NAME, 'withdrawal_recovered', sprintf('Withdrawal %s manually recovered from failed to approved.', $withdrawal->reference), $reason, $withdrawal);

                return ['action' => 'requeue', 'withdrawal' => $withdrawal];
            }

            if ($withdrawal->status === InstructorWithdrawalStatus::Approved) {
                return ['action' => 'requeue', 'withdrawal' => $withdrawal];
            }

            if ($withdrawal->status === InstructorWithdrawalStatus::Processing) {
                $current = InstructorPayoutAttempt::query()->forWithdrawal($withdrawal->id)->orderByDesc('execution_sequence')->lockForUpdate()->first();

                if ($current === null || $current->status->isTerminal() || $current->status === InstructorPayoutAttemptStatus::Succeeded) {
                    throw new PayoutExecutionException('There is no retryable payout attempt for this withdrawal.');
                }

                if ($current->status === InstructorPayoutAttemptStatus::Unknown) {
                    throw new PayoutExecutionException('This attempt\'s outcome is unknown — it must be reconciled before it can be retried.');
                }

                return ['action' => 'redispatch', 'attempt' => $current];
            }

            throw new PayoutExecutionException('This withdrawal has nothing to retry.');
        });

        if ($outcome['action'] === 'requeue') {
            return $this->queueExecution($outcome['withdrawal'], $actor);
        }

        $attempt = $outcome['attempt'];
        $this->audit->logOverride($actor, self::INTERNAL_LOG_NAME, 'payout_retry_requested', sprintf('Manual retry requested for payout attempt %s.', $attempt->reference), $reason, $attempt);
        InitiateInstructorPayout::dispatch($attempt->id);

        return $attempt;
    }

    public function cancelBeforeAcceptance(InstructorPayoutAttempt $attempt, User $actor): InstructorPayoutAttempt
    {
        $attempt = DB::transaction(function () use ($attempt): InstructorPayoutAttempt {
            $locked = InstructorPayoutAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isCancellable()) {
                throw new PayoutExecutionException('This payout attempt can no longer be cancelled — the provider may already have accepted it.');
            }

            $this->transitionAttempt($locked, InstructorPayoutAttemptStatus::Cancelled);

            $withdrawal = InstructorWithdrawalRequest::query()->whereKey($locked->withdrawal_request_id)->lockForUpdate()->firstOrFail();
            $withdrawal->forceFill(['status' => InstructorWithdrawalStatus::Approved])->save();

            return $locked;
        });

        $this->audit->logUser($actor, self::INTERNAL_LOG_NAME, 'payout_attempt_cancelled', sprintf('Payout attempt %s cancelled before provider acceptance.', $attempt->reference), $attempt);

        return $attempt;
    }

    // ── Internals: outcome persistence ────────────────────────────────

    /** @param array<string, mixed> $safeMetadata */
    private function persistProviderOutcome(
        string $attemptId,
        InstructorPayoutAttemptStatus $reported,
        ?string $providerPayoutId,
        ?string $providerStatus,
        ?string $safeReason,
        ?PayoutFailureCategory $category,
        array $safeMetadata,
    ): InstructorPayoutAttempt {
        return DB::transaction(function () use ($attemptId, $reported, $providerPayoutId, $providerStatus, $safeReason, $category, $safeMetadata): InstructorPayoutAttempt {
            $attempt = InstructorPayoutAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();

            // A truly terminal attempt (failed/cancelled/reversed) never
            // accepts another outcome, and a duplicate "succeeded" report
            // on an already-succeeded attempt is a no-op — but Succeeded
            // MUST still accept a legitimate Reversed report (a payout
            // can succeed and later be reversed; that is not a duplicate).
            if ($attempt->status->isTerminal()
                || ($attempt->status === InstructorPayoutAttemptStatus::Succeeded && $reported !== InstructorPayoutAttemptStatus::Reversed)) {
                return $attempt;
            }

            $acknowledgedNow = $providerPayoutId !== null;

            return match ($reported) {
                InstructorPayoutAttemptStatus::Succeeded => $this->finalizeSuccess($attempt, $providerPayoutId, $providerStatus),
                InstructorPayoutAttemptStatus::Reversed => $this->finalizeReversal($this->finalizeSuccess($attempt, $providerPayoutId, $providerStatus)),
                InstructorPayoutAttemptStatus::Failed => $this->handleFailureOutcome($attempt, $category, $safeReason, $acknowledgedNow, $providerPayoutId, $providerStatus),
                InstructorPayoutAttemptStatus::Unknown => $this->handleUnknownOutcome($attempt, $category, $safeReason, $providerPayoutId, $providerStatus),
                default => $this->applyInFlightOutcome($attempt, $reported, $providerPayoutId, $providerStatus, $safeMetadata),
            };
        });
    }

    private function applyInFlightOutcome(
        InstructorPayoutAttempt $attempt,
        InstructorPayoutAttemptStatus $next,
        ?string $providerPayoutId,
        ?string $providerStatus,
        array $safeMetadata,
    ): InstructorPayoutAttempt {
        if ($attempt->status->canTransitionTo($next)) {
            $this->transitionAttempt($attempt, $next, array_filter([
                'provider_payout_id' => $providerPayoutId,
                'provider_status' => $providerStatus,
                'acknowledged_at' => $next->isAcceptedByProvider() && $attempt->acknowledged_at === null ? now() : null,
                'last_synced_at' => now(),
                'encrypted_provider_metadata' => $safeMetadata !== [] ? $safeMetadata : null,
            ], fn ($v) => $v !== null));
        } else {
            $attempt->forceFill(['last_synced_at' => now()])->save();
        }

        if (in_array($next, [InstructorPayoutAttemptStatus::Submitted, InstructorPayoutAttemptStatus::Acknowledged, InstructorPayoutAttemptStatus::Processing], true)) {
            InstructorPayoutProcessing::dispatch($attempt->fresh());
        }

        return $attempt->fresh();
    }

    private function handleFailureOutcome(
        InstructorPayoutAttempt $attempt,
        ?PayoutFailureCategory $category,
        ?string $safeReason,
        bool $acknowledgedNow,
        ?string $providerPayoutId,
        ?string $providerStatus,
    ): InstructorPayoutAttempt {
        $category ??= PayoutFailureCategory::ReconciliationRequired;
        $acknowledged = $attempt->acknowledged_at !== null || $acknowledgedNow;

        // Bounded automatic retry: safe categories get another try (same
        // attempt, same idempotency key) instead of a terminal failure,
        // until the configured ceiling — never for Unknown outcomes.
        if ($category->isSafeForAutomaticRetry() && $this->settings->payout_auto_retry_enabled) {
            $attemptCount = $attempt->attempt_count + 1;
            $maxAttempts = max(1, $this->settings->payout_max_attempts);

            if ($attemptCount < $maxAttempts) {
                $attempt->forceFill([
                    'attempt_count' => $attemptCount,
                    'failure_category' => $category,
                    'safe_failure_message' => $safeReason,
                    'next_retry_at' => now()->addMinutes(min(60, 5 * $attemptCount)),
                    'last_synced_at' => now(),
                ])->save();

                InitiateInstructorPayout::dispatch($attempt->id)->delay($attempt->next_retry_at);

                return $attempt;
            }

            $this->audit->logSystem(self::INTERNAL_LOG_NAME, 'payout_retry_exhausted', sprintf('Payout attempt %s exhausted its automatic retry budget (%d attempts).', $attempt->reference, $attemptCount), $attempt, ['failure_category' => $category->value]);
        }

        $this->transitionAttempt($attempt, InstructorPayoutAttemptStatus::Failed, [
            'failed_at' => now(),
            'failure_category' => $category,
            'safe_failure_message' => $safeReason,
            'provider_payout_id' => $providerPayoutId,
            'provider_status' => $providerStatus,
            'acknowledged_at' => $acknowledged && $attempt->acknowledged_at === null ? now() : $attempt->acknowledged_at,
            'last_synced_at' => now(),
        ]);

        $withdrawal = InstructorWithdrawalRequest::query()->whereKey($attempt->withdrawal_request_id)->lockForUpdate()->firstOrFail();

        if (! $acknowledged) {
            // Never reached the provider — safe to return to approved
            // automatically; no explicit recovery needed.
            $withdrawal->forceFill(['status' => InstructorWithdrawalStatus::Approved])->save();

            $this->audit->logSystem(self::LOG_NAME, 'withdrawal_processing_started', sprintf('Withdrawal %s returned to approved after a pre-acceptance payout failure.', $withdrawal->reference), $withdrawal, ['failure_category' => $category->value]);

            return $attempt;
        }

        if ($category->releasesReservation()) {
            $withdrawal->allocations()->where('status', WithdrawalAllocationStatus::Reserved)->lockForUpdate()->get()
                ->each(fn ($allocation) => $allocation->forceFill(['status' => WithdrawalAllocationStatus::Released, 'released_at' => now()])->save());
        }

        $withdrawal->forceFill([
            'status' => InstructorWithdrawalStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => $safeReason,
        ])->save();

        $this->audit->logSystem(self::LOG_NAME, 'withdrawal_failed', sprintf('Withdrawal %s payout failed: %s', $withdrawal->reference, $safeReason ?? $category->label()), $withdrawal, ['failure_category' => $category->value]);
        InstructorPayoutFailed::dispatch($attempt->fresh());

        return $attempt->fresh();
    }

    private function handleUnknownOutcome(
        InstructorPayoutAttempt $attempt,
        ?PayoutFailureCategory $category,
        ?string $safeReason,
        ?string $providerPayoutId,
        ?string $providerStatus,
    ): InstructorPayoutAttempt {
        $this->transitionAttempt($attempt, InstructorPayoutAttemptStatus::Unknown, array_filter([
            'provider_payout_id' => $providerPayoutId,
            'provider_status' => $providerStatus,
            'failure_category' => $category ?? PayoutFailureCategory::ReconciliationRequired,
            'safe_failure_message' => $safeReason,
            'acknowledged_at' => $attempt->acknowledged_at ?? now(),
            'last_synced_at' => now(),
        ], fn ($v) => $v !== null));

        // Reservations are NEVER released on an unknown outcome — the
        // withdrawal stays processing until reconciliation resolves it.
        app(InstructorPayoutReconciliationServiceInterface::class)->raiseIssue(
            $attempt->fresh(),
            PayoutReconciliationIssueType::UnknownProviderOutcome,
            PayoutReconciliationSeverity::Critical,
            $safeReason ?? 'The payout outcome could not be confirmed.',
        );

        InstructorPayoutOutcomeUnknown::dispatch($attempt->fresh());

        return $attempt->fresh();
    }

    private function finalizeSuccess(InstructorPayoutAttempt $attempt, ?string $providerPayoutId, ?string $providerStatus): InstructorPayoutAttempt
    {
        if ($attempt->status === InstructorPayoutAttemptStatus::Succeeded) {
            return $attempt;
        }

        $withdrawal = InstructorWithdrawalRequest::query()->whereKey($attempt->withdrawal_request_id)->lockForUpdate()->firstOrFail();
        $allocations = $withdrawal->allocations()->where('status', WithdrawalAllocationStatus::Reserved)->lockForUpdate()->get();

        if ((int) $allocations->sum('amount_minor') !== $withdrawal->amount_minor) {
            throw new PayoutExecutionException('Payout finalization integrity check failed — the reserved amount no longer matches this withdrawal.');
        }

        InstructorEarning::query()->whereIn('id', $allocations->pluck('instructor_earning_id'))->lockForUpdate()->get();

        $allocations->each(fn ($allocation) => $allocation->forceFill(['status' => WithdrawalAllocationStatus::Consumed, 'consumed_at' => now()])->save());

        $withdrawal->forceFill([
            'status' => InstructorWithdrawalStatus::Paid,
            'paid_at' => now(),
        ])->save();

        $this->transitionAttempt($attempt, InstructorPayoutAttemptStatus::Succeeded, [
            'processed_at' => now(),
            'provider_payout_id' => $providerPayoutId,
            'provider_status' => $providerStatus,
            'acknowledged_at' => $attempt->acknowledged_at ?? now(),
            'last_synced_at' => now(),
        ]);

        $this->audit->logSystem(self::LOG_NAME, 'withdrawal_paid', sprintf('Withdrawal %s paid via attempt %s.', $withdrawal->reference, $attempt->reference), $withdrawal, ['attempt_reference' => $attempt->reference]);
        InstructorPayoutSucceeded::dispatch($attempt->fresh());

        return $attempt->fresh();
    }

    private function finalizeReversal(InstructorPayoutAttempt $attempt): InstructorPayoutAttempt
    {
        $withdrawal = InstructorWithdrawalRequest::query()->whereKey($attempt->withdrawal_request_id)->lockForUpdate()->firstOrFail();

        if ($withdrawal->status !== InstructorWithdrawalStatus::Paid) {
            throw new PayoutExecutionException('Only a paid withdrawal can be reversed.');
        }

        $allocations = $withdrawal->allocations()->where('status', WithdrawalAllocationStatus::Consumed)->lockForUpdate()->get();
        InstructorEarning::query()->whereIn('id', $allocations->pluck('instructor_earning_id'))->lockForUpdate()->get();

        $allocations->each(fn ($allocation) => $allocation->forceFill(['status' => WithdrawalAllocationStatus::Reversed, 'reversed_at' => now()])->save());

        $withdrawal->forceFill([
            'status' => InstructorWithdrawalStatus::Reversed,
            'reversed_at' => now(),
        ])->save();

        $this->transitionAttempt($attempt, InstructorPayoutAttemptStatus::Reversed, ['reversed_at' => now(), 'last_synced_at' => now()]);

        app(InstructorPayoutReconciliationServiceInterface::class)->raiseIssue(
            $attempt->fresh(),
            PayoutReconciliationIssueType::ReversedPayout,
            PayoutReconciliationSeverity::Warning,
            sprintf('Payout %s was reversed after previously succeeding.', $attempt->reference),
        );

        $this->audit->logSystem(self::LOG_NAME, 'withdrawal_reversed', sprintf('Withdrawal %s reversed — funds returned by the provider.', $withdrawal->reference), $withdrawal);
        InstructorPayoutReversed::dispatch($attempt->fresh());

        return $attempt->fresh();
    }

    // ── Small internals ───────────────────────────────────────────────

    /** @param array<string, mixed> $extra */
    private function transitionAttempt(InstructorPayoutAttempt $attempt, InstructorPayoutAttemptStatus $next, array $extra = []): InstructorPayoutAttempt
    {
        if (! $attempt->status->canTransitionTo($next)) {
            throw InvalidPayoutAttemptTransitionException::between($attempt->status, $next);
        }

        $attempt->forceFill([...$extra, 'status' => $next])->save();

        return $attempt;
    }

    private function generateAttemptReference(): string
    {
        do {
            $reference = sprintf('PA-%s-%s', now()->format('Ym'), strtoupper(Str::random(8)));
        } while (InstructorPayoutAttempt::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
