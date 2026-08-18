<?php

declare(strict_types=1);

namespace App\Compliance\Rules;

use App\Compliance\DTOs\SuspiciousActivitySignal;
use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Compliance\Support\ComplianceThresholdSnapshot;
use App\Messaging\Safety\Contracts\MessageSafetyFindingRepositoryInterface;
use App\Settings\ComplianceMonitoringSettings;
use Illuminate\Support\Facades\Date;

/**
 * THE ONLY BRIDGE from communication-safety findings into the
 * account-level compliance queue, and the reason P4 needed no second
 * admin resolution screen.
 *
 * It counts findings an ADMINISTRATOR HAS CONFIRMED — never open
 * findings, and never raw model output. That ordering is the whole
 * design: a model's opinion about one message can never open a
 * compliance flag on a real person; only a pattern of human-confirmed
 * concerns can, and even then the flag is evidence for review, not a
 * restriction.
 *
 * The rule itself is fully deterministic — a fixed threshold over a
 * fixed window — which is what keeps it honest inside an enum
 * documented as "one case per deterministic rule". `evidence` carries
 * counts only, respecting SuspiciousActivitySignal's contract that it
 * never contain narrative text; the models' reasons stay on the
 * findings themselves, marked probabilistic.
 */
final class RepeatedConfirmedMessageRisksRule
{
    public function __construct(
        private readonly ComplianceMonitoringSettings $settings,
        private readonly MessageSafetyFindingRepositoryInterface $findings,
    ) {}

    public function evaluate(int $senderId): ?SuspiciousActivitySignal
    {
        if (! $this->settings->repeated_confirmed_message_risks_enabled) {
            return null;
        }

        $windowDays = $this->settings->repeated_confirmed_message_risks_window_days;
        $cutoff = Date::now()->subDays($windowDays);

        $count = $this->findings->countConfirmedForSenderSince($senderId, $cutoff);

        if ($count < $this->settings->repeated_confirmed_message_risks_threshold) {
            return null;
        }

        return new SuspiciousActivitySignal(
            ruleCode: SuspiciousActivityRuleCode::RepeatedConfirmedMessageRisks,
            ruleVersion: 1,
            subjectId: $senderId,
            actorId: null,
            occurredAt: Date::now(),
            severity: SuspiciousActivityFlagSeverity::from($this->settings->repeated_confirmed_message_risks_severity),
            // Counts only — never a finding's reason, never message text.
            evidence: [
                'confirmed_finding_count' => $count,
                'window_days' => $windowDays,
                'threshold' => $this->settings->repeated_confirmed_message_risks_threshold,
            ],
            thresholdSnapshot: ComplianceThresholdSnapshot::capture(SuspiciousActivityRuleCode::RepeatedConfirmedMessageRisks, $this->settings),
            cooldownMinutes: $this->settings->repeated_confirmed_message_risks_cooldown_minutes,
        );
    }
}
