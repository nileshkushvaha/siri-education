<?php

declare(strict_types=1);

namespace App\Compliance\Support;

use App\Compliance\Enums\SuspiciousActivityRuleCode;

/**
 * Deterministic fingerprint — the dedup key backing
 * `suspicious_activity_flags.active_fingerprint`'s unique index. Two
 * concurrent or replayed evaluations of the same rule against the
 * same subject always compute the same fingerprint, so at most one
 * INSERT ever wins; the loser catches
 * UniqueConstraintViolationException and merges into the winner's
 * row instead — mirrors QualityAlertFingerprint's role for
 * quality_alerts.detection_fingerprint.
 */
final class SuspiciousActivityFingerprint
{
    public static function for(SuspiciousActivityRuleCode $rule, int $subjectId): string
    {
        return sprintf('compliance:%s:user:%d', $rule->value, $subjectId);
    }
}
