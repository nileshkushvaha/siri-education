<?php

declare(strict_types=1);

namespace App\Settings;

use App\Services\Mail\TransactionalMailSender;
use Spatie\LaravelSettings\Settings;

class MailSettings extends Settings
{
    // Sender
    public string $from_name;

    public string $from_email;

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

    public ?string $review_from_name;

    public ?string $review_from_email;

    /**
     * Transport selection. Blank means "inherit MAIL_MAILER"; any other value
     * must name a mailer configured in config/mail.php.
     *
     * @see TransactionalMailSender::mailer()
     */
    public string $driver;

    /**
     * SMTP connection details. These apply to the `smtp` driver ONLY — they are
     * merged into config('mail.mailers.smtp') at boot by
     * AppServiceProvider::applySettingsDrivenMailTransport(). API-based mailers
     * (Resend, SES, Postmark) ignore them entirely and read their credentials
     * from the environment, because those are deployment secrets.
     */
    public string $host;

    public int $port;

    public ?string $username;

    public ?string $password;  // stored encrypted

    public string $encryption;

    public int $connection_timeout;

    public static function group(): string
    {
        return 'mail';
    }
}
