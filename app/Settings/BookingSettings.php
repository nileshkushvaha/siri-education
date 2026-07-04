<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BookingSettings extends Settings
{
    /** Active bookings a teacher may hold per day. null = unlimited. */
    public ?int $max_daily_bookings_per_teacher;

    // Bookable window
    public int $min_lead_hours;

    public int $max_advance_days;

    /** Key of the AssignmentStrategyInterface used to auto-assign teachers. */
    public string $assignment_strategy;

    // Guest spam protection: Cloudflare Turnstile (honeypot + rate
    // limiting stay active regardless). Authenticated flows are exempt.
    public bool $captcha_enabled;

    public ?string $turnstile_site_key;

    public ?string $turnstile_secret_key;

    /** Key of the PaymentProviderInterface handling booking payments. */
    public string $payment_provider;

    /** Minutes a paid booking holds its slot awaiting payment. */
    public int $payment_reservation_minutes;

    // Notification channels (resolved by NotificationChannelResolver).
    // WhatsApp/SMS are future gateways — stub channels log until wired.
    public bool $channel_email_enabled;

    public bool $channel_whatsapp_enabled;

    public bool $channel_sms_enabled;

    public static function group(): string
    {
        return 'booking';
    }
}
