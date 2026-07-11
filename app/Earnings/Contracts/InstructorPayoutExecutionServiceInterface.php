<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\DTOs\NormalizedPayoutEvent;
use App\Earnings\DTOs\PayoutStatusResult;
use App\Earnings\Exceptions\PayoutExecutionException;
use App\Models\InstructorPayoutAttempt;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;

/**
 * The only writer of payout-attempt state and the only caller allowed
 * to move a withdrawal through its execution segment
 * (approved → processing → paid/failed/reversed). No Filament action,
 * job, command, or controller mutates these rows directly.
 */
interface InstructorPayoutExecutionServiceInterface
{
    /**
     * Validates maker-checker + reservation + snapshot integrity, creates
     * the attempt, moves the withdrawal to `processing`, and — only after
     * commit — dispatches the execution job. Never calls the provider.
     *
     * @throws PayoutExecutionException
     */
    public function queueExecution(InstructorWithdrawalRequest $withdrawal, User $actor): InstructorPayoutAttempt;

    /**
     * Called only by InitiateInstructorPayout. Calls the provider outside
     * any open transaction, then persists the normalized result in a new,
     * short transaction.
     */
    public function execute(InstructorPayoutAttempt $attempt): InstructorPayoutAttempt;

    /** Applies a reconciliation-fetched status result to the attempt/withdrawal. */
    public function applyProviderStatus(InstructorPayoutAttempt $attempt, PayoutStatusResult $status): InstructorPayoutAttempt;

    /** Idempotent — a duplicate event has no additional financial effect. */
    public function handleNormalizedEvent(NormalizedPayoutEvent $event): void;

    /**
     * Manual, permission-gated retry with a mandatory reason. Reuses the
     * same attempt/idempotency key for a still-open logical execution;
     * queues a brand-new attempt (new execution sequence) only when the
     * withdrawal has returned to `approved`.
     *
     * @throws PayoutExecutionException
     */
    public function retry(InstructorWithdrawalRequest $withdrawal, User $actor, string $reason): InstructorPayoutAttempt;

    /** Only safe before provider acceptance. */
    public function cancelBeforeAcceptance(InstructorPayoutAttempt $attempt, User $actor): InstructorPayoutAttempt;
}
