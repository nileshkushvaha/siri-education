<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Removes the "Refund Enabled" toggle from Payment Settings.
 *
 * Same defect as the sandbox flags removed in
 * 2026_11_02_100200: it read as a platform-wide financial kill switch
 * and was not one. Nothing consumed it — not the cancellation refund
 * path, not the lesson-outcome refund path, not the finance-exception
 * provider refund — so an operator could switch "Refund Enabled" OFF
 * and every refund would continue exactly as before.
 *
 * It is deliberately NOT being wired up instead. The SRS defines no
 * platform-wide refund kill switch, and inventing one would create a
 * control that can strand a student's money mid-cancellation.
 *
 * The refund control the SRS DOES define lives in the wallet module
 * (SRS §13.x "Wallet refund enabled") and already exists as
 * InstructorEarningSettings::lesson_refund_execution_enabled, which is
 * genuinely enforced by LessonWalletRefundService. That one is
 * untouched.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->deleteIfExists('payment_configuration.refund_enabled');
    }

    public function down(): void
    {
        $this->migrator->add('payment_configuration.refund_enabled', true);
    }
};
