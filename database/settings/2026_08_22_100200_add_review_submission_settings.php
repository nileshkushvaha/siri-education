<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('reviews.rating_min', 1);
        $this->migrator->add('reviews.rating_max', 5);
        $this->migrator->add('reviews.written_review_required', false);
        $this->migrator->add('reviews.review_min_length', 10);
        $this->migrator->add('reviews.review_max_length', 2000);
        $this->migrator->add('reviews.rating_dimensions_enabled', true);
        $this->migrator->add('reviews.review_max_tags', 5);
    }
};
