<?php

declare(strict_types=1);

namespace App\Compliance\Rules;

use App\Compliance\DTOs\SuspiciousActivitySignal;
use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Compliance\Support\ComplianceThresholdSnapshot;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Settings\ComplianceMonitoringSettings;
use Illuminate\Support\Facades\Date;

/**
 * SRS §9.13/§24.26 — an administrator making an unusual number of
 * manual wallet balance adjustments within a rolling window. No
 * domain event exists for WalletLedgerService::adjustment(), so this
 * rule is evaluated via a direct call from that method rather than an
 * event listener — the narrowest possible integration point, already
 * gated behind the `manage` Wallet permission and a mandatory reason.
 * The subject is the acting administrator, never the wallet owner —
 * this rule watches admin behavior, not customer behavior.
 */
final class UnusualManualWalletAdjustmentsRule
{
    public function __construct(
        private readonly ComplianceMonitoringSettings $settings,
    ) {}

    public function evaluate(User $admin): ?SuspiciousActivitySignal
    {
        if (! $this->settings->unusual_manual_wallet_adjustments_enabled) {
            return null;
        }

        $windowHours = $this->settings->unusual_manual_wallet_adjustments_window_hours;
        $cutoff = Date::now()->subHours($windowHours);

        $count = WalletLedgerEntry::query()
            ->where('created_by', $admin->id)
            ->where('entry_type', 'admin_adjustment')
            ->where('posted_at', '>=', $cutoff)
            ->count();

        if ($count < $this->settings->unusual_manual_wallet_adjustments_threshold) {
            return null;
        }

        return new SuspiciousActivitySignal(
            ruleCode: SuspiciousActivityRuleCode::UnusualManualWalletAdjustments,
            ruleVersion: 1,
            subjectId: $admin->id,
            actorId: $admin->id,
            occurredAt: Date::now(),
            severity: SuspiciousActivityFlagSeverity::from($this->settings->unusual_manual_wallet_adjustments_severity),
            evidence: [
                'admin_adjustment_count' => $count,
                'window_hours' => $windowHours,
                'threshold' => $this->settings->unusual_manual_wallet_adjustments_threshold,
            ],
            thresholdSnapshot: ComplianceThresholdSnapshot::capture(SuspiciousActivityRuleCode::UnusualManualWalletAdjustments, $this->settings),
            cooldownMinutes: $this->settings->unusual_manual_wallet_adjustments_cooldown_minutes,
        );
    }
}
