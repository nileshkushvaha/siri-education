<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    // Application Information
    public string $app_name;

    public ?string $app_short_name;

    public ?string $organization_name;

    public string $support_email;

    public ?string $support_phone;

    public ?string $website_url;

    public ?string $address;

    // Branding
    public ?string $logo;

    public ?string $favicon;

    // Header
    public bool $header_top_bar_enabled;

    public ?string $facebook_url;

    public ?string $instagram_url;

    public ?string $x_url;

    public ?string $youtube_url;

    // Localization — country/detection settings live in LocalizationSettings.
    public string $default_timezone;

    // Application
    public string $default_currency;

    // Footer
    public ?string $footer_copyright;

    public ?string $footer_text;

    // Reading (WordPress-style homepage control)
    public string $homepage_display;  // 'template' | 'static_page'

    public ?string $homepage_id;       // Page UUID when homepage_display = 'static_page'

    public static function group(): string
    {
        return 'general';
    }
}
