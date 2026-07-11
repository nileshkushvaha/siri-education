<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Off until an admin deliberately enables the withdrawal flow.
        $this->migrator->add('instructor_earnings.withdrawals_enabled', false);
        // ₹500.00 (INR-primary platform) — a safe floor against dust requests.
        $this->migrator->add('instructor_earnings.minimum_withdrawal_minor', 50000);
        $this->migrator->add('instructor_earnings.maximum_withdrawal_minor', null);
        $this->migrator->add('instructor_earnings.maximum_active_requests_per_instructor', 1);
        $this->migrator->add('instructor_earnings.payout_method_verification_required', true);
        $this->migrator->add('instructor_earnings.instructor_cancellation_enabled', true);
        // True = a request must pass under_review before it can be approved.
        $this->migrator->add('instructor_earnings.withdrawal_review_required', true);
    }
};
