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

    public ?string $logo_dark;

    public ?string $favicon;

    // Localization — country/detection settings live in LocalizationSettings.
    public string $default_timezone;

    public string $default_language;

    public string $date_format;

    public string $time_format;

    // Application
    public string $default_currency;

    public int $decimal_precision;

    public bool $maintenance_mode;

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
