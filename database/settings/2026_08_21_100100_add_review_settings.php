<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Disabled-safe: the whole eligibility layer ships OFF.
        $this->migrator->add('reviews.reviews_enabled', false);
        $this->migrator->add('reviews.paid_lesson_reviews_enabled', true);
        // Demo lessons default to private feedback only — never public
        // by default, since a free trial lesson is a weaker signal.
        $this->migrator->add('reviews.demo_review_policy', 'private_only');
        $this->migrator->add('reviews.review_window_days', 14);
    }
};
