<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class WhatsAppSettings extends Settings
{
    public bool $enabled;

    public string $number;

    public string $default_message;

    public bool $desktop_visible;

    public bool $mobile_visible;

    /** @var list<string> */
    public array $allowed_pages;

    /** @var list<string> */
    public array $excluded_pages;

    public static function group(): string
    {
        return 'whatsapp';
    }
}
