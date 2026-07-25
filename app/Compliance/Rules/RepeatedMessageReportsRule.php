<?php

declare(strict_types=1);

namespace App\Compliance\Rules;

use App\Compliance\DTOs\SuspiciousActivitySignal;
use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Compliance\Support\ComplianceThresholdSnapshot;
use App\Models\MessageReport;
use App\Settings\ComplianceMonitoringSettings;
use Illuminate\Support\Facades\Date;

/**
 * GAP-017 requirement #7 — integrates message reporting with the
 * existing rule-based compliance monitoring infrastructure (Phase 30)
 * rather than inventing a parallel mechanism. Counts distinct reports
 * against messages sent by the same user within a rolling window —
 * evidence for human review only, never an automatic restriction
 * (that stays a separate, explicit admin action via
 * MessagingService::applyRestriction()).
 */
final class RepeatedMessageReportsRule
{
    public function __construct(
        private readonly ComplianceMonitoringSettings $settings,
    ) {}

    public function evaluate(int $reportedUserId): ?SuspiciousActivitySignal
    {
        if (! $this->settings->repeated_message_reports_enabled) {
            return null;
        }

        $windowDays = $this->settings->repeated_message_reports_window_days;
        $cutoff = Date::now()->subDays($windowDays);

        $count = MessageReport::query()
            ->whereHas('message', fn ($query) => $query->where('sender_id', $reportedUserId))
            ->where('message_reports.created_at', '>=', $cutoff)
            ->count();

        if ($count < $this->settings->repeated_message_reports_threshold) {
            return null;
        }

        return new SuspiciousActivitySignal(
            ruleCode: SuspiciousActivityRuleCode::RepeatedMessageReports,
            ruleVersion: 1,
            subjectId: $reportedUserId,
            actorId: null,
            occurredAt: Date::now(),
            severity: SuspiciousActivityFlagSeverity::from($this->settings->repeated_message_reports_severity),
            evidence: [
                'report_count' => $count,
                'window_days' => $windowDays,
                'threshold' => $this->settings->repeated_message_reports_threshold,
            ],
            thresholdSnapshot: ComplianceThresholdSnapshot::capture(SuspiciousActivityRuleCode::RepeatedMessageReports, $this->settings),
            cooldownMinutes: $this->settings->repeated_message_reports_cooldown_minutes,
        );
    }
}
