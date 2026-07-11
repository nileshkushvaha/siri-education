<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Internal attempt lifecycle — never a direct mapping of any provider's
 * own status vocabulary (the provider adapter normalizes into this set;
 * see InstructorPayoutProviderInterface::normalizeEvent()). `unknown` is
 * the safety state for "provider may have accepted the request but we
 * cannot confirm it" — it is never auto-retried, only reconciled.
 */
enum InstructorPayoutAttemptStatus: string
{
    case Created = 'created';
    case Dispatching = 'dispatching';
    case Submitted = 'submitted';
    case Acknowledged = 'acknowledged';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Dispatching => 'Dispatching',
            self::Submitted => 'Submitted',
            self::Acknowledged => 'Acknowledged',
            self::Processing => 'Processing',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Unknown => 'Unknown',
            self::Reversed => 'Reversed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Created, self::Dispatching, self::Submitted, self::Acknowledged, self::Processing => 'warning',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Unknown => 'danger',
            self::Reversed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /** The provider has confirmed it received the request (acceptance boundary). */
    public function isAcceptedByProvider(): bool
    {
        return match ($this) {
            self::Acknowledged, self::Processing, self::Succeeded, self::Failed, self::Reversed => true,
            default => false,
        };
    }

    /** No further automatic action is possible from this attempt row. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Failed, self::Cancelled, self::Reversed => true,
            default => false,
        };
    }

    /** Cancellation is only safe before the provider has accepted the request. */
    public function isCancellable(): bool
    {
        return match ($this) {
            self::Created, self::Dispatching => true,
            default => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Created => [self::Dispatching, self::Cancelled],
            // A single synchronous initiate() call can legitimately
            // report ANY of these as its immediate outcome — a fake or
            // fast real provider may confirm success (or failure) before
            // the job ever observes an intermediate submitted/acknowledged
            // state. Cancellation stays reachable too (pre-acceptance).
            self::Dispatching => [self::Submitted, self::Acknowledged, self::Processing, self::Succeeded, self::Failed, self::Unknown, self::Cancelled],
            self::Submitted => [self::Acknowledged, self::Processing, self::Succeeded, self::Failed, self::Unknown],
            self::Acknowledged => [self::Processing, self::Succeeded, self::Failed, self::Unknown],
            self::Processing => [self::Succeeded, self::Failed, self::Unknown, self::Reversed],
            // Unknown only ever resolves through reconciliation, using
            // provider-confirmed evidence — never an unguarded retry.
            self::Unknown => [self::Succeeded, self::Failed, self::Reversed, self::Unknown],
            self::Succeeded => [self::Reversed],
            self::Failed, self::Reversed, self::Cancelled => [],
        };
    }
}
