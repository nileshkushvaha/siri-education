<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Off by default — quality-alert detection is a conservative,
        // opt-in feature until an operator has reviewed and tuned the
        // thresholds for their platform.
        $this->migrator->add('reviews.quality_alerts_enabled', false);
        $this->migrator->add('reviews.low_rating_threshold', 2);
        $this->migrator->add('reviews.single_low_rating_alert_enabled', true);
        $this->migrator->add('reviews.repeated_low_rating_count', 3);
        $this->migrator->add('reviews.repeated_low_rating_window_days', 30);
        $this->migrator->add('reviews.repeated_no_show_count', 2);
        $this->migrator->add('reviews.repeated_no_show_window_days', 30);
        $this->migrator->add('reviews.repeated_cancellation_count', 3);
        $this->migrator->add('reviews.repeated_cancellation_window_days', 30);
    }
};
