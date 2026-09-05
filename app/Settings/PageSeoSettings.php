<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Per-URL meta title/description for the template-driven public pages
 * (home, blog, instructors, FAQs, auth screens). CMS pages and posts keep
 * their own SEO fields; this covers the routes that are rendered from
 * Blade templates and previously had hardcoded meta.
 */
class PageSeoSettings extends Settings
{
    /**
     * Keyed by SeoRoute value; each entry holds meta_title and
     * meta_description (either may be null).
     */
    public array $pages;

    public static function group(): string
    {
        return 'page_seo';
    }
}
