<?php

declare(strict_types=1);

namespace App\Compliance\Rules;

use App\Compliance\DTOs\SuspiciousActivitySignal;
use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Compliance\Support\ComplianceThresholdSnapshot;
use App\Models\LoginHistory;
use App\Models\User;
use App\Settings\ComplianceMonitoringSettings;
use Illuminate\Support\Facades\Date;

/**
 * SRS §9.13/§24.26 — repeated failed logins against one account
 * within a rolling window. Counts against `login_histories`, a table
 * every attempt (successful or not) already persists to for other
 * purposes — no new tracking is introduced. A single bounded,
 * indexed, per-subject COUNT triggered by one login-failure event is
 * not "historical full-database scanning" (a periodic full-table
 * scan would be); this is a targeted lookup for one user.
 */
final class RepeatedFailedLoginsRule
{
    public function __construct(
        private readonly ComplianceMonitoringSettings $settings,
    ) {}

    public function evaluate(User $user): ?SuspiciousActivitySignal
    {
        if (! $this->settings->repeated_failed_logins_enabled) {
            return null;
        }

        $windowMinutes = $this->settings->repeated_failed_logins_window_minutes;
        $cutoff = Date::now()->subMinutes($windowMinutes);

        $count = LoginHistory::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'success')
            ->where('logged_in_at', '>=', $cutoff)
            ->count();

        if ($count < $this->settings->repeated_failed_logins_threshold) {
            return null;
        }

        return new SuspiciousActivitySignal(
            ruleCode: SuspiciousActivityRuleCode::RepeatedFailedLogins,
            ruleVersion: 1,
            subjectId: $user->id,
            actorId: null,
            occurredAt: Date::now(),
            severity: SuspiciousActivityFlagSeverity::from($this->settings->repeated_failed_logins_severity),
            evidence: [
                'failed_login_count' => $count,
                'window_minutes' => $windowMinutes,
                'threshold' => $this->settings->repeated_failed_logins_threshold,
            ],
            thresholdSnapshot: ComplianceThresholdSnapshot::capture(SuspiciousActivityRuleCode::RepeatedFailedLogins, $this->settings),
            cooldownMinutes: $this->settings->repeated_failed_logins_cooldown_minutes,
        );
    }
}
