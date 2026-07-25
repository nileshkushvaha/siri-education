<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('compliance_monitoring.repeated_message_reports_enabled', true);
        $this->migrator->add('compliance_monitoring.repeated_message_reports_threshold', 3);
        $this->migrator->add('compliance_monitoring.repeated_message_reports_window_days', 30);
        $this->migrator->add('compliance_monitoring.repeated_message_reports_severity', 'high');
        $this->migrator->add('compliance_monitoring.repeated_message_reports_cooldown_minutes', 1440);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('compliance_monitoring.repeated_message_reports_enabled');
        $this->migrator->deleteIfExists('compliance_monitoring.repeated_message_reports_threshold');
        $this->migrator->deleteIfExists('compliance_monitoring.repeated_message_reports_window_days');
        $this->migrator->deleteIfExists('compliance_monitoring.repeated_message_reports_severity');
        $this->migrator->deleteIfExists('compliance_monitoring.repeated_message_reports_cooldown_minutes');
    }
};
