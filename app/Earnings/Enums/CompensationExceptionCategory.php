<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Why a completed lesson's earning creation was blocked. Categories
 * drive the retry policy: configuration failures stay retryable until
 * an admin fixes the setup, transient failures are retried by the
 * sweep, permanently ineligible lessons are never retried again.
 */
enum CompensationExceptionCategory: string
{
    case MissingAgreement = 'missing_agreement';
    case InvalidAgreement = 'invalid_agreement';
    case InvalidCurrency = 'invalid_currency';
    case UnsupportedDuration = 'unsupported_duration';
    case TransientFailure = 'transient_failure';
    case PermanentlyIneligible = 'permanently_ineligible';

    public function label(): string
    {
        return match ($this) {
            self::MissingAgreement => 'No applicable agreement',
            self::InvalidAgreement => 'Invalid agreement configuration',
            self::InvalidCurrency => 'Invalid currency configuration',
            self::UnsupportedDuration => 'Unsupported lesson duration',
            self::TransientFailure => 'Transient failure',
            self::PermanentlyIneligible => 'Permanently ineligible',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::MissingAgreement => 'danger',
            self::InvalidAgreement => 'danger',
            self::InvalidCurrency => 'warning',
            self::UnsupportedDuration => 'warning',
            self::TransientFailure => 'info',
            self::PermanentlyIneligible => 'gray',
        };
    }

    public function isRetryEligible(): bool
    {
        return $this !== self::PermanentlyIneligible;
    }

    /** The audit event name (the missing-agreement one keeps its Phase 14.2 name — the admin NotificationMapper listens for it). */
    public function auditEvent(): string
    {
        return $this === self::MissingAgreement
            ? 'earning_blocked_no_agreement'
            : 'earning_blocked_'.$this->value;
    }
}
