<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class MailSettings extends Settings
{
    // Sender
    public string $from_name;

    public string $from_email;

    public ?string $transactional_domain;

    public ?string $auth_from_name;

    public ?string $auth_from_email;

    public ?string $booking_from_name;

    public ?string $booking_from_email;

    public ?string $payment_from_name;

    public ?string $payment_from_email;

    public ?string $tutor_from_name;

    public ?string $tutor_from_email;

    public ?string $wallet_from_name;

    public ?string $wallet_from_email;

    public ?string $support_from_name;

    public ?string $support_from_email;

    public ?string $admin_from_name;

    public ?string $admin_from_email;

    // SMTP
    public string $driver;

    public string $host;

    public int $port;

    public ?string $username;

    public ?string $password;  // stored encrypted

    public string $encryption;

    // Queue
    public bool $queue_emails;

    // Advanced
    public int $connection_timeout;

    public int $retry_attempts;

    public static function group(): string
    {
        return 'mail';
    }
}
