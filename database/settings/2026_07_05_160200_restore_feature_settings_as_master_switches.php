<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Per the settings spec, FeatureSettings is the single on/off
 * switch per feature module — domain settings classes hold configuration
 * only. Re-adds features.wallet_enabled/referral_enabled/recording_enabled
 * (removed by 2026_07_05_150000) and removes wallet.enabled /
 * referral.enabled, which would otherwise be a second, competing switch
 * for the same feature.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('features.wallet_enabled', false);
        $this->migrator->add('features.referral_enabled', false);
        $this->migrator->add('features.recording_enabled', false);

        $this->migrator->deleteIfExists('wallet.enabled');
        $this->migrator->deleteIfExists('referral.enabled');
    }

    public function down(): void
    {
        $this->migrator->add('wallet.enabled', false);
        $this->migrator->add('referral.enabled', false);

        $this->migrator->deleteIfExists('features.wallet_enabled');
        $this->migrator->deleteIfExists('features.referral_enabled');
        $this->migrator->deleteIfExists('features.recording_enabled');
    }
};
