<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Settings\MailSettings;

final class TransactionalMailSender
{
    public function __construct(private readonly MailSettings $settings) {}

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
