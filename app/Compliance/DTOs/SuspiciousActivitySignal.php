<?php

declare(strict_types=1);

namespace App\Compliance\DTOs;

use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use Illuminate\Support\Carbon;

/**
 * What one rule evaluation produced when its threshold was crossed —
 * the only input ComplianceMonitoringService::record() accepts. Rules
 * never write to the database themselves; they only ever build this.
 * `evidence` must contain nothing but safe, masked, aggregate data
 * (counts, thresholds, window sizes, ids) — never passwords, secrets,
 * bank details, full payment data, KYC documents, raw request
 * bodies, or narrative text.
 */
final readonly class SuspiciousActivitySignal
{
    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $thresholdSnapshot
     */
    public function __construct(
        public SuspiciousActivityRuleCode $ruleCode,
        public int $ruleVersion,
        public int $subjectId,
        public ?int $actorId,
        public Carbon $occurredAt,
        public SuspiciousActivityFlagSeverity $severity,
        public array $evidence,
        public array $thresholdSnapshot,
        public int $cooldownMinutes,
    ) {}
}
