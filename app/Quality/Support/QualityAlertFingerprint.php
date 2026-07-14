<?php

declare(strict_types=1);

namespace App\Quality\Support;

use App\Quality\Enums\InstructorQualityAlertType;

/**
 * Deterministic fingerprints — the sole deduplication mechanism,
 * backed by `quality_alerts.detection_fingerprint`'s unique index.
 * Two concurrent or replayed evaluations of the *same* signal always
 * compute the *same* fingerprint, so at most one insert ever succeeds
 * (the loser catches `UniqueConstraintViolationException` and treats
 * that as "already recorded" — the exact convention
 * `OpenLessonReviewEligibilityAction` already uses).
 *
 * Single-occurrence types (SingleLowRating, InstructorNoShow,
 * SeriousReviewReport) key on the triggering source record — that
 * record can only ever produce one alert. Repeated/threshold types
 * key on an "episode" number instead: how many *terminal* (already
 * resolved/dismissed/duplicate) alerts of this type already exist for
 * the instructor. Two concurrent evaluations of the same still-open
 * episode both compute the same episode number and collide into one
 * row; once that episode is resolved, the next threshold crossing
 * computes a new episode number and is free to create a fresh alert —
 * this is what makes "a new alert only for a genuinely new window or
 * escalation" true without needing a lockable parent row to exist
 * before the first alert does.
 */
final class QualityAlertFingerprint
{
    public static function forSource(InstructorQualityAlertType $type, int $instructorId, string $sourceId): string
    {
        return sprintf('quality-alert:%s:%d:%s', $type->value, $instructorId, $sourceId);
    }

    public static function forEpisode(InstructorQualityAlertType $type, int $instructorId, int $episode): string
    {
        return sprintf('quality-alert:%s:%d:episode-%d', $type->value, $instructorId, $episode);
    }
}
