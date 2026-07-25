<?php

declare(strict_types=1);

namespace App\Compliance\Enums;

/**
 * The reviewing administrator's classification of a resolved flag —
 * distinct from `status` (the workflow state). Recorded only on
 * `resolve()`; `dismiss()` never requires a classification, only a
 * reason. This is a record of human judgement, never an automated
 * verdict, and never drives any system action.
 */
enum SuspiciousActivityFlagDecision: string
{
    case ConfirmedRisk = 'confirmed_risk';
    case FalsePositive = 'false_positive';
    case Inconclusive = 'inconclusive';

    public function label(): string
    {
        return match ($this) {
            self::ConfirmedRisk => 'Confirmed risk',
            self::FalsePositive => 'False positive',
            self::Inconclusive => 'Inconclusive',
        };
    }
}
