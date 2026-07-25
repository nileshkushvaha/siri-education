<?php

declare(strict_types=1);

namespace App\Alerts\Enums;

/**
 * The routing bucket an alert's type belongs to (SRS §26.29 "Alert
 * Routing"). This codebase has no dedicated "Finance Admin"/
 * "Operations Admin"/"Technical Admin" role — every domain grants
 * granular permissions to the single `manager` role instead — so each
 * category maps to the existing domain-view permission a relevant
 * manager already holds ({@see notificationPermission()}), reusing
 * `AdminRecipientResolver::forPermission()` rather than inventing a
 * parallel team/role system. `super_admin` is always included by that
 * resolver regardless of the permission checked, so
 * `CrossDomainCritical` (no natural existing domain permission) still
 * reaches Super Admin as SRS §26.28/§26.41 require.
 */
enum OperationalAlertCategory: string
{
    case BookingMeeting = 'booking_meeting';
    case Finance = 'finance';
    case NotificationQueueSystem = 'notification_queue_system';
    case CrossDomainCritical = 'cross_domain_critical';

    public function label(): string
    {
        return match ($this) {
            self::BookingMeeting => 'Booking / Meeting',
            self::Finance => 'Finance',
            self::NotificationQueueSystem => 'Notification / Queue / System',
            self::CrossDomainCritical => 'Cross-Domain Critical',
        };
    }

    /** The existing permission whose holders are notified for this category. */
    public function notificationPermission(): string
    {
        return match ($this) {
            self::BookingMeeting => 'ViewAny:Booking',
            self::Finance => 'ViewAny:Wallet',
            self::NotificationQueueSystem => 'queue_monitor.view',
            self::CrossDomainCritical => 'ViewAny:OperationalAlert',
        };
    }
}
