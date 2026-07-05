<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NewsletterSubscriberStatus;
use App\Models\NewsletterSubscriber;
use App\Notifications\Newsletter\NewsletterWelcomeNotification;
use App\Services\Mail\TransactionalNotificationService;
use Illuminate\Support\Str;

final class NewsletterSubscriptionService
{
    public function __construct(
        private readonly AuditTrailService $auditTrail,
        private readonly TransactionalNotificationService $notifications,
    ) {}

    public function subscribe(string $email, ?string $name = null, array $properties = []): NewsletterSubscriber
    {
        $wasAlreadySubscribed = NewsletterSubscriber::where('email', $email)
            ->where('status', NewsletterSubscriberStatus::Subscribed)
            ->exists();

        $subscriber = NewsletterSubscriber::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'status' => NewsletterSubscriberStatus::Subscribed,
                'unsubscribe_token' => Str::random(48),
                'source' => $properties['source'] ?? null,
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
            ],
        );

        $this->auditTrail->logGuest(
            logName: 'newsletter',
            event: 'newsletter_subscribed',
            description: 'Newsletter subscription submitted',
            subject: $subscriber,
            guestName: (string) $name,
            guestEmail: $email,
            properties: $properties,
        );

        if (! $wasAlreadySubscribed) {
            $this->sendWelcome($subscriber);
        }

        return $subscriber;
    }

    public function unsubscribe(string $token): bool
    {
        $subscriber = NewsletterSubscriber::where('unsubscribe_token', $token)->first();

        if ($subscriber === null) {
            return false;
        }

        $subscriber->update([
            'status' => NewsletterSubscriberStatus::Unsubscribed,
            'unsubscribed_at' => now(),
        ]);

        return true;
    }

    private function sendWelcome(NewsletterSubscriber $subscriber): void
    {
        $this->notifications->routeMail($subscriber->email, new NewsletterWelcomeNotification($subscriber));
    }
}
