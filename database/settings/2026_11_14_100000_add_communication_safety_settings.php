<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Communication-safety escalation thresholds (P4).
 *
 * Ships ENABLED, unlike the AI capability flag it sits behind, because
 * this rule is fully deterministic and consumes only findings an
 * administrator already confirmed by hand. With `ai.communication_
 * moderation_enabled` off, the only findings that exist are
 * deterministic leakage matches — which are exactly the pattern this
 * rule should escalate on when an admin keeps confirming them.
 *
 * The threshold is deliberately higher than the message-reports rule's:
 * a confirmed contact-sharing finding is a much weaker signal about a
 * person than a stranger reporting them, and one or two should never
 * open a compliance case.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('compliance_monitoring.repeated_confirmed_message_risks_enabled', true);
        $this->migrator->add('compliance_monitoring.repeated_confirmed_message_risks_threshold', 3);
        $this->migrator->add('compliance_monitoring.repeated_confirmed_message_risks_window_days', 30);
        $this->migrator->add('compliance_monitoring.repeated_confirmed_message_risks_severity', 'medium');
        $this->migrator->add('compliance_monitoring.repeated_confirmed_message_risks_cooldown_minutes', 10080);
    }

    public function down(): void
    {
        foreach ([
            'repeated_confirmed_message_risks_enabled',
            'repeated_confirmed_message_risks_threshold',
            'repeated_confirmed_message_risks_window_days',
            'repeated_confirmed_message_risks_severity',
            'repeated_confirmed_message_risks_cooldown_minutes',
        ] as $key) {
            $this->migrator->deleteIfExists("compliance_monitoring.{$key}");
        }
    }
};
