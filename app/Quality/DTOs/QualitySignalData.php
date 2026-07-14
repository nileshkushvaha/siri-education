<?php

declare(strict_types=1);

namespace App\Quality\DTOs;

use App\Quality\Enums\InstructorQualityAlertType;
use App\Quality\Enums\QualityAlertSourceType;
use Carbon\CarbonImmutable;

/**
 * Everything RecordQualityAlertSignalAction needs to lock-or-create
 * one alert row — built by a specific detector (low-rating, no-show,
 * cancellation, report), never by a caller directly.
 */
final readonly class QualitySignalData
{
    /** @param array<string, mixed> $summaryMetadata sanitized evidence references only — never raw student/report text */
    public function __construct(
        public int $instructorId,
        public InstructorQualityAlertType $type,
        public QualityAlertSourceType $sourceType,
        public string $sourceId,
        public string $fingerprint,
        public CarbonImmutable $triggeredAt,
        public ?CarbonImmutable $windowStart,
        public ?CarbonImmutable $windowEnd,
        public ?int $signalCount,
        public array $summaryMetadata = [],
        /** Set only for repeated/threshold types — episode 1 is the first-ever alert of this type for the instructor; episode > 1 signals a recurrence after a prior resolution (drives InstructorQualityAlertEscalated). */
        public ?int $episodeNumber = null,
    ) {}
}
