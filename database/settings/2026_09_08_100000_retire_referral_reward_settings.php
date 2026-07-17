<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Phase 19C — referral_campaigns is now the single authoritative source
 * of reward rules. The float-based `referral.*` settings (never read by
 * any workflow — Phase 19A/19B audits confirmed configuration-only)
 * are retired so no competing reward configuration survives. No legacy
 * value is mapped into campaign money and no default campaign is
 * fabricated — campaigns are created deliberately by administrators.
 * The module switch stays features.referral_enabled, untouched.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->deleteIfExists('referral.reward_type');
        $this->migrator->deleteIfExists('referral.referrer_reward_amount');
        $this->migrator->deleteIfExists('referral.referee_reward_amount');
        $this->migrator->deleteIfExists('referral.reward_unlock_days');
    }

    public function down(): void
    {
        $this->migrator->add('referral.reward_type', 'wallet_credit');
        $this->migrator->add('referral.referrer_reward_amount', 0.0);
        $this->migrator->add('referral.referee_reward_amount', 0.0);
        $this->migrator->add('referral.reward_unlock_days', 0);
    }
};
