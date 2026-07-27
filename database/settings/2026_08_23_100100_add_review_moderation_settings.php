<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // risk_based: safe reviews publish automatically, anything the
        // sanitizer already flagged waits for a human — the
        // conservative default given automated content scoring exists.
        $this->migrator->add('reviews.moderation_model', 'risk_based');
        $this->migrator->add('reviews.auto_publish_clean_reviews', true);
    }
};
