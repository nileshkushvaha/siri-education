<?php

declare(strict_types=1);

namespace App\Providers;

use App\Booking\Events\BookingCancelled;
use App\Booking\Events\BookingCompleted;
use App\Booking\Events\BookingConfirmed;
use App\Booking\Events\BookingPaymentSucceeded;
use App\Booking\Events\BookingRequested;
use App\Booking\Events\BookingRescheduled;
use App\Booking\Events\MeetingCreated;
use App\Booking\Events\MeetingUpdated;
use App\Events\ActivityCreated;
use App\Events\Auth\LoginFailed;
use App\Events\Auth\UserApproved;
use App\Events\Auth\UserLoggedIn;
use App\Events\Auth\UserLoggedOut;
use App\Events\Auth\UserRegistered;
use App\Listeners\Auth\LogLoginActivity;
use App\Listeners\Auth\SendApprovalNotification;
use App\Listeners\Auth\SendRegistrationNotifications;
use App\Listeners\Auth\SendWelcomeNotification;
use App\Listeners\Booking\CreateMeetingOnBookingConfirmed;
use App\Listeners\Booking\RecordBookingLifecycleAudit;
use App\Listeners\Booking\SendBookingNotifications;
use App\Listeners\Booking\SendMeetingNotifications;
use App\Listeners\Booking\SyncPaymentOnCancellation;
use App\Listeners\Lesson\CreateLessonOnBookingConfirmed;
use App\Listeners\Lesson\SyncLessonOnBookingCancelled;
use App\Listeners\Lesson\SyncLessonOnBookingCompleted;
use App\Listeners\Mail\LogMailSending;
use App\Listeners\Mail\LogMailSent;
use App\Listeners\Mail\LogNotificationFailed;
use App\Listeners\Mail\LogNotificationSending;
use App\Listeners\Mail\LogNotificationSent;
use App\Listeners\Mail\LogResendEmailEvent;
use App\Listeners\NotifyAdminsOnActivity;
use App\Listeners\NotifyInstructorOnProfileActivity;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Resend\Laravel\Events\EmailBounced;
use Resend\Laravel\Events\EmailComplained;
use Resend\Laravel\Events\EmailDelivered;
use Resend\Laravel\Events\EmailDeliveryDelayed;
use Resend\Laravel\Events\EmailFailed;
use Resend\Laravel\Events\EmailSent;
use Resend\Laravel\Events\EmailSuppressed;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        ActivityCreated::class => [
            NotifyAdminsOnActivity::class,
            NotifyInstructorOnProfileActivity::class,
        ],
        UserRegistered::class => [
            SendRegistrationNotifications::class,
        ],
        // Welcome email fires only after the user clicks the verification link
        Verified::class => [
            SendWelcomeNotification::class,
        ],
        // Fired from EditUser when admin changes status from INACTIVE → ACTIVE
        UserApproved::class => [
            SendApprovalNotification::class,
        ],
        UserLoggedIn::class => [
            [LogLoginActivity::class, 'handleUserLoggedIn'],
        ],
        UserLoggedOut::class => [
            [LogLoginActivity::class, 'handleUserLoggedOut'],
        ],
        LoginFailed::class => [
            [LogLoginActivity::class, 'handleLoginFailed'],
        ],
        NotificationSending::class => [
            LogNotificationSending::class,
        ],
        NotificationSent::class => [
            LogNotificationSent::class,
        ],
        NotificationFailed::class => [
            LogNotificationFailed::class,
        ],
        MessageSending::class => [
            LogMailSending::class,
        ],
        MessageSent::class => [
            LogMailSent::class,
        ],
        EmailSent::class => [
            [LogResendEmailEvent::class, 'handleEmailSent'],
        ],
        EmailDelivered::class => [
            [LogResendEmailEvent::class, 'handleEmailDelivered'],
        ],
        EmailFailed::class => [
            [LogResendEmailEvent::class, 'handleEmailFailed'],
        ],
        EmailBounced::class => [
            [LogResendEmailEvent::class, 'handleEmailBounced'],
        ],
        EmailComplained::class => [
            [LogResendEmailEvent::class, 'handleEmailComplained'],
        ],
        EmailDeliveryDelayed::class => [
            [LogResendEmailEvent::class, 'handleEmailDelayed'],
        ],
        EmailSuppressed::class => [
            [LogResendEmailEvent::class, 'handleEmailSuppressed'],
        ],
        BookingRequested::class => [
            [SendBookingNotifications::class, 'handleRequested'],
            [RecordBookingLifecycleAudit::class, 'handleRequested'],
        ],
        BookingConfirmed::class => [
            [SendBookingNotifications::class, 'handleConfirmed'],
            [RecordBookingLifecycleAudit::class, 'handleConfirmed'],
            CreateMeetingOnBookingConfirmed::class,
            CreateLessonOnBookingConfirmed::class,
        ],
        BookingCancelled::class => [
            [SendBookingNotifications::class, 'handleCancelled'],
            [RecordBookingLifecycleAudit::class, 'handleCancelled'],
            SyncPaymentOnCancellation::class,
            SyncLessonOnBookingCancelled::class,
        ],
        BookingRescheduled::class => [
            [SendBookingNotifications::class, 'handleRescheduled'],
            [RecordBookingLifecycleAudit::class, 'handleRescheduled'],
        ],
        BookingCompleted::class => [
            [SendBookingNotifications::class, 'handleCompleted'],
            [RecordBookingLifecycleAudit::class, 'handleCompleted'],
            SyncLessonOnBookingCompleted::class,
        ],
        BookingPaymentSucceeded::class => [
            [SendBookingNotifications::class, 'handlePaymentSucceeded'],
        ],
        MeetingCreated::class => [
            [SendMeetingNotifications::class, 'handleCreated'],
        ],
        MeetingUpdated::class => [
            [SendMeetingNotifications::class, 'handleUpdated'],
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }

    public function boot(): void
    {
        //
    }
}
