<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Every payout failure is categorized before it ever reaches a status
 * transition — a generic exception is never enough to decide what
 * happens to the withdrawal or its reservations. Categories drive three
 * independent decisions: whether the withdrawal returns to `approved`
 * for a fresh execution, whether reservations release, and whether a
 * bounded automatic retry is safe (see InstructorPayoutExecutionService).
 */
enum PayoutFailureCategory: string
{
    case PreProviderValidation = 'pre_provider_validation';
    case ProviderRejected = 'provider_rejected';
    case ProviderRetryable = 'provider_retryable';
    case ProviderPermanent = 'provider_permanent';
    case ProviderTimeoutBeforeAcceptance = 'provider_timeout_before_acceptance';
    case ProviderTimeoutUnknownAcceptance = 'provider_timeout_unknown_acceptance';
    case ProviderUnavailable = 'provider_unavailable';
    case LocalPersistenceFailure = 'local_persistence_failure';
    case ReconciliationRequired = 'reconciliation_required';
    case DestinationInvalid = 'destination_invalid';
    case InsufficientProviderBalance = 'insufficient_provider_balance';
    case ConfigurationError = 'configuration_error';

    public function label(): string
    {
        return match ($this) {
            self::PreProviderValidation => 'Pre-provider validation failed',
            self::ProviderRejected => 'Rejected by provider',
            self::ProviderRetryable => 'Provider — retryable',
            self::ProviderPermanent => 'Provider — permanent failure',
            self::ProviderTimeoutBeforeAcceptance => 'Timeout before acceptance',
            self::ProviderTimeoutUnknownAcceptance => 'Timeout — acceptance unknown',
            self::ProviderUnavailable => 'Provider unavailable',
            self::LocalPersistenceFailure => 'Local persistence failure',
            self::ReconciliationRequired => 'Reconciliation required',
            self::DestinationInvalid => 'Destination invalid',
            self::InsufficientProviderBalance => 'Insufficient provider balance',
            self::ConfigurationError => 'Configuration error',
        };
    }

    /**
     * Confirmed-permanent destination failures release reservations —
     * the money returns to the pool because it can never reach this
     * destination. Every other category keeps the reservation: either
     * the instructor still gets paid on retry, or the outcome is not
     * yet certain enough to say otherwise.
     */
    public function releasesReservation(): bool
    {
        return match ($this) {
            self::ProviderPermanent, self::DestinationInvalid => true,
            default => false,
        };
    }

    /**
     * Safe for the bounded automatic retry policy: duplicate execution
     * must be provably impossible for these categories (the provider
     * never accepted the request, or explicitly asked for a retry).
     */
    public function isSafeForAutomaticRetry(): bool
    {
        return match ($this) {
            self::ProviderRetryable, self::ProviderTimeoutBeforeAcceptance, self::ProviderUnavailable => true,
            default => false,
        };
    }

    /**
     * The instructor did nothing wrong — an operational/provider issue,
     * never surfaced as an instructor-facing "failure".
     */
    public function isOperationalNotInstructorFault(): bool
    {
        return match ($this) {
            self::ProviderUnavailable, self::InsufficientProviderBalance, self::LocalPersistenceFailure,
            self::ConfigurationError, self::ReconciliationRequired => true,
            default => false,
        };
    }
}
