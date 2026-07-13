<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Conservative defaults: the disposition bridge ships OFF, and
        // every ambiguous outcome routes to a human.
        $this->migrator->add('instructor_earnings.financial_disposition_enabled', false);
        // manual_review | normal_earning | no_earning
        $this->migrator->add('instructor_earnings.student_no_show_compensation_policy', 'manual_review');
        // manual_review | refund | forfeit
        $this->migrator->add('instructor_earnings.both_absent_financial_policy', 'manual_review');
    }
};
