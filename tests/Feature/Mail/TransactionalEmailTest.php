<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\Auth\WelcomeNotification;
use App\Notifications\Booking\BookingConfirmedNotification;
use App\Services\Mail\EmailLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Str;
use Resend\Laravel\Events\EmailDelivered;
use Resend\Laravel\Events\EmailFailed;
use Resend\Laravel\ResendServiceProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class TransactionalEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_resend_mailer_is_configured(): void
    {
        $this->assertSame('resend', config('mail.mailers.resend.transport'));
        $this->assertArrayHasKey('resend', config('services'));
        $this->assertTrue(class_exists(ResendServiceProvider::class));

        $env = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('MAIL_MAILER=resend', $env);
        $this->assertStringContainsString('RESEND_API_KEY=', $env);
        $this->assertStringContainsString('MAIL_FROM_ADDRESS=', $env);
        $this->assertStringContainsString('MAIL_FROM_NAME=', $env);
    }

    public function test_mail_notification_is_logged_as_pending(): void
    {
        $user = User::factory()->create();
        $notification = new WelcomeNotification;
        $notification->id = (string) Str::uuid();

        app(EmailLogService::class)->markNotificationPending(
            new NotificationSending($user, $notification, 'mail'),
        );

        $this->assertDatabaseHas('email_logs', [
            'id' => $notification->id,
            'notification_id' => $notification->id,
            'notification_type' => WelcomeNotification::class,
            'category' => 'auth',
            'status' => 'pending',
            'queued' => true,
        ]);
    }

    public function test_failed_mail_notification_marks_email_log_failed(): void
    {
        $user = User::factory()->create();
        $notification = new WelcomeNotification;
        $notification->id = (string) Str::uuid();

        EmailLog::query()->create([
            'id' => $notification->id,
            'notification_id' => $notification->id,
            'notification_type' => WelcomeNotification::class,
            'category' => 'auth',
            'status' => 'pending',
        ]);

        app(EmailLogService::class)->markNotificationFailed(
            new NotificationFailed($user, $notification, 'mail', [
                'exception' => new \RuntimeException('Resend rejected the request.'),
            ]),
        );

        $this->assertDatabaseHas('email_logs', [
            'id' => $notification->id,
            'status' => 'failed',
            'error' => 'Resend rejected the request.',
        ]);
    }

    public function test_message_sending_adds_log_and_resend_idempotency_headers(): void
    {
        $id = (string) Str::uuid();
        $message = (new Email)
            ->from('booking@example.com')
            ->to('student@example.com')
            ->subject('Booking confirmed')
            ->text('Confirmed.');

        app(EmailLogService::class)->recordSending(
            new MessageSending($message, [
                '__laravel_notification_id' => $id,
                '__laravel_notification' => BookingConfirmedNotification::class,
                '__laravel_notification_queued' => true,
            ]),
        );

        $this->assertSame($id, $message->getHeaders()->get('X-App-Email-Log-Id')?->getBodyAsString());
        $this->assertSame($id, $message->getHeaders()->get('Resend-Idempotency-Key')?->getBodyAsString());
        $this->assertDatabaseHas('email_logs', [
            'id' => $id,
            'notification_id' => $id,
            'category' => 'booking',
            'status' => 'pending',
            'subject' => 'Booking confirmed',
        ]);
    }

    public function test_resend_webhooks_reconcile_provider_statuses(): void
    {
        EmailLog::query()->create([
            'id' => (string) Str::uuid(),
            'notification_id' => (string) Str::uuid(),
            'category' => 'booking',
            'status' => 'sent',
            'provider' => 'resend',
            'provider_message_id' => 'email_123',
        ]);

        event(new EmailDelivered([
            'type' => 'email.delivered',
            'data' => ['email_id' => 'email_123'],
        ]));

        $this->assertDatabaseHas('email_logs', [
            'provider_message_id' => 'email_123',
            'status' => 'delivered',
        ]);

        event(new EmailFailed([
            'type' => 'email.failed',
            'data' => ['email_id' => 'email_123', 'reason' => 'domain not verified'],
        ]));

        $this->assertDatabaseHas('email_logs', [
            'provider_message_id' => 'email_123',
            'status' => 'failed',
            'error' => 'domain not verified',
        ]);
    }
}
