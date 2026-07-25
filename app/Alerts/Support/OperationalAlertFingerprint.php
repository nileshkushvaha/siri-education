<?php

declare(strict_types=1);

namespace App\Alerts\Support;

use App\Alerts\Enums\OperationalAlertType;

/**
 * Deterministic dedup key backing `operational_alerts.active_fingerprint`'s
 * unique index — mirrors `SuspiciousActivityFingerprint`'s role for
 * `suspicious_activity_flags`. Two concurrent or repeated occurrences
 * of the same type against the same subject always compute the same
 * fingerprint, so at most one row is ever active for it at a time.
 */
final class OperationalAlertFingerprint
{
    public static function for(OperationalAlertType $type, ?string $subjectType, ?string $subjectId): string
    {
        return sprintf('alert:%s:%s:%s', $type->value, $subjectType ?? 'none', $subjectId ?? 'none');
    }
}
