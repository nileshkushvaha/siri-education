<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Phase 17U.2 §3 — retires the Finding S-1 decoy. `features.reviews_enabled`
 * was a same-named duplicate of the real canonical switch,
 * `reviews.reviews_enabled` (added first, in
 * 2026_08_21_100100_add_review_settings.php), and no review-domain code
 * ever read the `features` copy — toggling it on the old Platform
 * Foundation "Feature Flags" form had zero runtime effect. Deleting this
 * key does not touch `reviews.reviews_enabled` at all, so the real
 * switch's current value (and behavior) is completely unaffected.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->deleteIfExists('features.reviews_enabled');
    }
};
