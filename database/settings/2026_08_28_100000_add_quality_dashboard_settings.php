<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('reviews.quality_dashboard_low_rating_threshold', 2.5);
        $this->migrator->add('reviews.quality_dashboard_high_rating_threshold', 4.5);
        $this->migrator->add('reviews.quality_dashboard_min_review_count', 3);
    }
};
