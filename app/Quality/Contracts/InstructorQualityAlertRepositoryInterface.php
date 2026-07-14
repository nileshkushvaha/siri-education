<?php

declare(strict_types=1);

namespace App\Quality\Contracts;

use App\Models\InstructorQualityAlert;
use App\Quality\Enums\InstructorQualityAlertType;
use App\Quality\Enums\QualityAlertSourceType;

interface InstructorQualityAlertRepositoryInterface
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): InstructorQualityAlert;

    /** Refetch with a row lock — call only inside a transaction. */
    public function lock(InstructorQualityAlert $alert): InstructorQualityAlert;

    public function findByFingerprint(string $fingerprint): ?InstructorQualityAlert;

    /** How many terminal (resolved/dismissed/duplicate/expired) alerts of this type already exist for the instructor — the "episode number" input for repeated-type fingerprints. */
    public function countTerminalForInstructorAndType(int $instructorId, InstructorQualityAlertType $type): int;

    /** The active (Open/UnderReview) alert whose source matches, if any — used to flag it for reevaluation without deleting it. */
    public function findActiveForSource(QualityAlertSourceType $sourceType, string $sourceId): ?InstructorQualityAlert;
}
