<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\EmailLog;
use App\Notifications\Contracts\TransactionalEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class EmailLogService
{
    public const HEADER = 'X-App-Email-Log-Id';

    public function markNotificationPending(NotificationSending $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        EmailLog::query()->firstOrCreate(
            ['notification_id' => $event->notification->id],
            [
                'id' => $event->notification->id,
                'notification_type' => $event->notification::class,
                'category' => $this->category($event->notification),
                'provider' => app()->environment('production') ? 'resend' : (string) config('mail.default'),
                'mailer' => (string) config('mail.default'),
                'status' => 'pending',
                'queued' => in_array(ShouldQueue::class, class_implements($event->notification), true),
                'attempts' => 0,
                'metadata' => [
                    'notifiable_type' => is_object($event->notifiable) ? $event->notifiable::class : get_debug_type($event->notifiable),
                    'notifiable_id' => $event->notifiable->getKey() ?? null,
                ],
            ],
        );
    }

    public function recordSending(MessageSending $event): void
    {
        $id = (string) ($event->data['__laravel_notification_id'] ?? Str::uuid());

        $event->message->getHeaders()->addTextHeader(self::HEADER, $id);
        if (! $event->message->getHeaders()->has('Resend-Idempotency-Key')) {
            $event->message->getHeaders()->addTextHeader('Resend-Idempotency-Key', $id);
        }

        EmailLog::query()->updateOrCreate(
            ['notification_id' => $id],
            $this->payloadFromMessage($event->message, $event->data, [
                'id' => $id,
                'status' => 'pending',
                'attempts' => 1,
            ]),
        );
    }

    public function recordSent(MessageSent $event): void
    {
        $id = $this->headerValue($event->message, self::HEADER)
            ?? (string) ($event->data['__laravel_notification_id'] ?? '');

        if ($id === '') {
            return;
        }

        $providerMessageId = $this->headerValue($event->message, 'X-Resend-Email-ID')
            ?? $event->sent->getMessageId();

        EmailLog::query()
            ->where('notification_id', $id)
            ->update([
                'status' => 'sent',
                'provider_message_id' => $providerMessageId,
                'sent_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
    }

    public function markNotificationFailed(NotificationFailed $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        $exception = $event->data['exception'] ?? null;

        EmailLog::query()
            ->where('notification_id', $event->notification->id)
            ->update([
                'status' => 'failed',
                'error' => $exception instanceof \Throwable ? $exception->getMessage() : null,
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function markNotificationSent(NotificationSent $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }

        EmailLog::query()
            ->where('notification_id', $event->notification->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'sent',
                'sent_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function recordProviderEvent(array $payload, string $status): void
    {
        $messageId = $this->providerMessageId($payload);

        if ($messageId === null) {
            return;
        }

        $updates = [
            'status' => $status,
            'metadata' => ['resend_webhook' => $payload],
            'updated_at' => now(),
        ];

        if ($status === 'delivered') {
            $updates['delivered_at'] = now();
        }

        if (in_array($status, ['failed', 'bounced', 'complained', 'suppressed'], true)) {
            $updates['failed_at'] = now();
            $updates['error'] = $this->providerError($payload);
        }

        EmailLog::query()
            ->where('provider_message_id', $messageId)
            ->update($updates);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payloadFromMessage(Email $message, array $data, array $overrides): array
    {
        $from = $message->getFrom()[0] ?? null;

        return array_merge([
            'notification_type' => $data['__laravel_notification'] ?? null,
            'category' => $this->categoryFromClass((string) ($data['__laravel_notification'] ?? '')),
            'provider' => app()->environment('production') ? 'resend' : (string) config('mail.default'),
            'mailer' => (string) config('mail.default'),
            'subject' => $message->getSubject(),
            'from_address' => $from instanceof Address ? $from->getAddress() : null,
            'from_name' => $from instanceof Address ? $from->getName() : null,
            'to' => $this->addresses($message->getTo()),
            'cc' => $this->addresses($message->getCc()),
            'bcc' => $this->addresses($message->getBcc()),
            'queued' => (bool) ($data['__laravel_notification_queued'] ?? false),
            'metadata' => ['laravel_mail_data_keys' => array_keys($data)],
        ], $overrides);
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array{address: string, name: string}>
     */
    private function addresses(array $addresses): array
    {
        return array_map(
            fn (Address $address): array => [
                'address' => $address->getAddress(),
                'name' => $address->getName(),
            ],
            $addresses,
        );
    }

    private function headerValue(Email $message, string $header): ?string
    {
        return $message->getHeaders()->has($header)
            ? $message->getHeaders()->get($header)?->getBodyAsString()
            : null;
    }

    private function category(object $notification): string
    {
        return $notification instanceof TransactionalEmail
            ? $notification->emailCategory()
            : $this->categoryFromClass($notification::class);
    }

    private function categoryFromClass(string $class): string
    {
        return match (true) {
            str_contains($class, '\\Auth\\') => 'auth',
            str_contains($class, '\\Booking\\') => 'booking',
            str_contains($class, '\\Payment\\') => 'payment',
            str_contains($class, '\\Tutor\\'), str_contains($class, '\\Instructor\\') => 'tutor',
            str_contains($class, '\\Wallet\\') => 'wallet',
            str_contains($class, '\\Support\\'), str_contains($class, '\\Forms\\'), str_contains($class, '\\Cms\\') => 'support',
            str_contains($class, '\\Admin\\') => 'admin',
            default => 'general',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerMessageId(array $payload): ?string
    {
        $value = Arr::get($payload, 'data.email_id')
            ?? Arr::get($payload, 'data.id')
            ?? Arr::get($payload, 'email_id')
            ?? Arr::get($payload, 'id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerError(array $payload): ?string
    {
        $value = Arr::get($payload, 'data.reason')
            ?? Arr::get($payload, 'data.error')
            ?? Arr::get($payload, 'reason')
            ?? Arr::get($payload, 'error');

        return is_scalar($value) ? (string) $value : null;
    }
}
