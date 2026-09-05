<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class SeoSettings extends Settings
{
    // SEO
    public ?string $meta_title;

    public ?string $meta_description;

    public ?string $meta_keywords;

    public string $robots;

    public ?string $canonical_url;

    // Verification & Analytics
    public ?string $google_search_console_verification;

    public ?string $google_analytics_id;

    public ?string $google_tag_manager_id;

    public ?string $facebook_pixel_id;

    // Open Graph / Social
    public ?string $og_image;

    public string $twitter_card;

    /**
     * Per-page overrides for the template-rendered public routes, keyed by
     * SeoRoute value; each entry holds meta_title, meta_description,
     * meta_keywords, canonical_url and og_image (any may be null).
     */
    public array $pages;

    public static function group(): string
    {
        return 'seo';
    }
}
