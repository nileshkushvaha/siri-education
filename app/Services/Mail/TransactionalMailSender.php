<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Settings\MailSettings;

final class TransactionalMailSender
{
    public function __construct(private readonly MailSettings $settings) {}

    /**
     * The mailer transactional notifications should send through.
     *
     * The settings table is the authority — an administrator changing Mail
     * Driver in the admin panel must take effect without a deploy, so this is
     * read before MAIL_MAILER rather than after it. Previously nothing read
     * this setting at all: production was hard-coded to 'resend', which meant
     * the field was editable but inert.
     *
     * A stored driver is only honoured when it names a mailer that actually
     * exists in config/mail.php. A stale value left behind by a removed mailer
     * would otherwise hard-fail every send with no way to recover from the UI,
     * so an unknown or blank value falls back to MAIL_MAILER.
     */
    public function mailer(): string
    {
        $configured = $this->settings->driver;

        if ($configured !== '' && array_key_exists($configured, (array) config('mail.mailers', []))) {
            return $configured;
        }

        return (string) config('mail.default');
    }

    /**
     * @return array{address: string, name: string}
     */
    public function resolve(string $key): array
    {
        $key = str_replace('-', '_', strtolower($key));
        $emailProperty = "{$key}_from_email";
        $nameProperty = "{$key}_from_name";

        return [
            'address' => $this->validEmail($this->settings->{$emailProperty} ?? null)
                ?? $this->validEmail($this->settings->from_email)
                ?? (string) config('mail.from.address'),
            'name' => filled($this->settings->{$nameProperty} ?? null)
                ? (string) $this->settings->{$nameProperty}
                : ($this->settings->from_name ?: (string) config('mail.from.name')),
        ];
    }

    private function validEmail(?string $email): ?string
    {
        if (! is_string($email) || $email === '') {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
