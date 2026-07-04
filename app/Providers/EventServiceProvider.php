<?php

declare(strict_types=1);

namespace App\Providers;

use App\Booking\Events\BookingCancelled;
use App\Booking\Events\BookingCompleted;
use App\Booking\Events\BookingConfirmed;
use App\Booking\Events\BookingRequested;
use App\Booking\Events\BookingRescheduled;
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
use App\Listeners\Booking\RecordBookingLifecycleAudit;
use App\Listeners\Booking\SendBookingNotifications;
use App\Listeners\Booking\SyncPaymentOnCancellation;
use App\Listeners\NotifyAdminsOnActivity;
use App\Listeners\NotifyInstructorOnProfileActivity;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

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
        BookingRequested::class => [
            [SendBookingNotifications::class, 'handleRequested'],
            [RecordBookingLifecycleAudit::class, 'handleRequested'],
        ],
        BookingConfirmed::class => [
            [SendBookingNotifications::class, 'handleConfirmed'],
            [RecordBookingLifecycleAudit::class, 'handleConfirmed'],
        ],
        BookingCancelled::class => [
            [SendBookingNotifications::class, 'handleCancelled'],
            [RecordBookingLifecycleAudit::class, 'handleCancelled'],
            SyncPaymentOnCancellation::class,
        ],
        BookingRescheduled::class => [
            [SendBookingNotifications::class, 'handleRescheduled'],
            [RecordBookingLifecycleAudit::class, 'handleRescheduled'],
        ],
        BookingCompleted::class => [
            [SendBookingNotifications::class, 'handleCompleted'],
            [RecordBookingLifecycleAudit::class, 'handleCompleted'],
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
