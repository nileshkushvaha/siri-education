<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Events\Auth\UserRegistered;
use App\Notifications\Auth\RegistrationPendingNotification;
use App\Notifications\Auth\WelcomeNotification;
use App\Services\Auth\EmailVerificationOtpService;
use App\Settings\RegistrationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendRegistrationNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function handle(UserRegistered $event): void
    {
        $user = $event->user;
        $settings = app(RegistrationSettings::class);

        // ── Activity: user registered ────────────────────────────────────────
        activity('auth')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['ip' => $event->ipAddress])
            ->log('User registered');

        // ── Branch: pending admin approval ───────────────────────────────────
        if ($settings->require_admin_approval) {
            // Tell the registrant their account is pending review
            $user->notify(new RegistrationPendingNotification);

            // Log the event — ActivityObserver fires ActivityCreated which routes
            // through NotificationMapper → AdminNotificationService → bell notification
            // for every super_admin. Do NOT send AdminNewRegistrationNotification here
            // directly; that would create a duplicate notification for the same event.
            activity('auth')
                ->causedBy($user)
                ->performedOn($user)
                ->event('registration_pending_approval')
                ->withProperties(['ip' => $event->ipAddress])
                ->log('New registration pending admin approval');

            return;
        }

        // ── Branch: email auto-verified ──────────────────────────────────────
        if ($settings->auto_verify_email) {
            activity('auth')
                ->causedBy($user)
                ->performedOn($user)
                ->event('email_auto_verified')
                ->withProperties(['ip' => $event->ipAddress])
                ->log('Email auto-verified during registration');

            // Send welcome email immediately — Verified event won't fire since
            // we used forceFill rather than markEmailAsVerified()
            if ($settings->send_welcome_email) {
                $user->notify(new WelcomeNotification);

                activity('auth')
                    ->causedBy($user)
                    ->performedOn($user)
                    ->event('welcome_email_queued')
                    ->withProperties(['ip' => $event->ipAddress])
                    ->log('Welcome email queued');
            }

            return;
        }

        // ── Default: email the one-time verification code ────────────────────
        app(EmailVerificationOtpService::class)->issue($user);
    }

    public function failed(UserRegistered $event, Throwable $exception): void
    {
        Log::error('SendRegistrationNotifications failed', [
            'user_id' => $event->user->id,
            'email' => $event->user->email,
            'exception' => $exception->getMessage(),
        ]);
    }
}
