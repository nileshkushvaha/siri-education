<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/**
 * Lifecycle actions recorded in booking_activities — the booking's
 * domain timeline. This complements (never replaces) the unified
 * Activity Log audit trail fed via AuditTrailService.
 */
enum BookingActivityAction: string
{
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case GuestInvited = 'guest_invited';
    case GuestResponded = 'guest_responded';
    case PaymentStatusChanged = 'payment_status_changed';
    case MeetingLinked = 'meeting_linked';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Confirmed => 'Confirmed',
            self::Rescheduled => 'Rescheduled',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
            self::NoShow => 'No Show',
            self::GuestInvited => 'Guest Invited',
            self::GuestResponded => 'Guest Responded',
            self::PaymentStatusChanged => 'Payment Status Changed',
            self::MeetingLinked => 'Meeting Linked',
        };
    }
}
