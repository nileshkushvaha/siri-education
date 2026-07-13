<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Never a full student name by default — an initial-only label
        // is the conservative default given no prior public-display
        // privacy decision exists yet.
        $this->migrator->add('reviews.public_review_identity_mode', 'first_name_initial');
    }
};
