<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BookingSettings extends Settings
{
    public int $demo_duration_minutes;

    public int $reservation_expiry_minutes;

    public int $cancellation_window_hours;

    public int $reschedule_limit;

    public int $no_show_grace_minutes;

    public int $auto_completion_delay_minutes;

    /** Active bookings a teacher may hold per day. null = unlimited. */
    public ?int $max_daily_bookings_per_teacher;

    // Bookable window — the only fields BookingWindowRule reads for
    // lead-time/advance-window enforcement. Named to match the original
    // settings spec; do not add a second pair of fields for this concept.
    public int $minimum_booking_notice_minutes;

    /**
     * Lead time for FREE demo bookings only — shorter than the paid
     * notice so a student can try an instructor who is free right now.
     */
    public int $demo_minimum_booking_notice_minutes;

    public int $maximum_advance_booking_days;

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
