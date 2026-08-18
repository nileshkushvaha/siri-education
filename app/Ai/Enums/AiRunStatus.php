<?php

declare(strict_types=1);

namespace App\Ai\Enums;

/**
 * The lifecycle of one AI execution, persisted as ai_runs.status.
 *
 *   Blocked    refused before any provider call — feature flag off,
 *              not configured, or over budget. Costs nothing, but is
 *              still recorded so "the feature silently did nothing"
 *              is never invisible to an operator.
 *   Running    the provider call is in flight.
 *   Succeeded  a response arrived AND (for structured work) passed
 *              schema validation.
 *   Rejected   a response arrived but failed validation. Distinct from
 *              Failed on purpose: tokens WERE spent, so cost accounting
 *              must include it, and a rising Rejected rate is a prompt
 *              or schema problem, not an outage.
 *   Failed     the provider call itself did not produce a usable
 *              response.
 */
enum AiRunStatus: string
{
    case Blocked = 'blocked';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Blocked => 'Blocked',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Blocked => 'gray',
            self::Running => 'info',
            self::Succeeded => 'success',
            self::Rejected => 'warning',
            self::Failed => 'danger',
        };
    }

    /** Terminal states are the only ones that carry a completed_at. */
    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }
}
