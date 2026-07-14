<?php

declare(strict_types=1);

namespace App\Quality\DTOs;

use App\Models\InstructorQualityAlert;
use Carbon\CarbonImmutable;

/**
 * Privacy-safe projection of one alert for a future admin UI —
 * deliberately excludes student contact details, private feedback
 * text, payment information, instructor compensation, raw provider
 * metadata, and raw report explanations. Only ids/counts/timestamps
 * plus the already-sanitized `summary_metadata` reach this DTO.
 */
final readonly class InstructorQualityAlertAdminData
{
    /** @param array<string, mixed> $summaryMetadata @param array<string, int|bool> $thresholdSnapshot */
    public function __construct(
        public string $alertId,
        public int $instructorId,
        public string $instructorName,
        public string $alertType,
        public string $severity,
        public string $status,
        public ?string $sourceType,
        public ?string $sourceId,
        public ?int $signalCount,
        public array $thresholdSnapshot,
        public array $summaryMetadata,
        public CarbonImmutable $triggeredAt,
        public bool $needsReevaluation,
        public ?int $assignedTo,
        public ?CarbonImmutable $reviewedAt,
        public ?CarbonImmutable $resolvedAt,
        public ?string $resolutionAction,
        public ?string $resolutionReason,
    ) {}

    public static function fromAlert(InstructorQualityAlert $alert): self
    {
        return new self(
            alertId: $alert->id,
            instructorId: $alert->instructor_id,
            instructorName: $alert->instructor->name,
            alertType: $alert->alert_type->value,
            severity: $alert->severity->value,
            status: $alert->status->value,
            sourceType: $alert->source_type?->value,
            sourceId: $alert->source_id,
            signalCount: $alert->signal_count,
            thresholdSnapshot: $alert->threshold_snapshot,
            summaryMetadata: $alert->summary_metadata ?? [],
            triggeredAt: CarbonImmutable::instance($alert->triggered_at),
            needsReevaluation: $alert->needs_reevaluation,
            assignedTo: $alert->assigned_to,
            reviewedAt: $alert->reviewed_at === null ? null : CarbonImmutable::instance($alert->reviewed_at),
            resolvedAt: $alert->resolved_at === null ? null : CarbonImmutable::instance($alert->resolved_at),
            resolutionAction: $alert->resolution_action?->value,
            resolutionReason: $alert->resolution_reason,
        );
    }
}
