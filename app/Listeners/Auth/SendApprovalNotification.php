<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Events\Auth\UserApproved;
use App\Notifications\Auth\AccountApprovedNotification;
use App\Notifications\Auth\WelcomeNotification;
use App\Services\Auth\EmailVerificationOtpService;
use App\Settings\RegistrationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendApprovalNotification implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function handle(UserApproved $event): void
    {
        $user = $event->user;
        $regSettings = app(RegistrationSettings::class);

        // Always tell the user their account has been approved
        $user->notify(new AccountApprovedNotification);

        if (! $user->hasVerifiedEmail()) {
            // Email still needs verification — email the one-time code now
            // that the account is active.
            app(EmailVerificationOtpService::class)->issue($user);

            return;
        }

        // Email already verified — send welcome email if enabled
        if ($regSettings->send_welcome_email) {
            $user->notify(new WelcomeNotification);
        }
    }

    public function failed(UserApproved $event, Throwable $exception): void
    {
        Log::error('SendApprovalNotification failed', [
            'user_id' => $event->user->id,
            'email' => $event->user->email,
            'exception' => $exception->getMessage(),
        ]);
    }
}
