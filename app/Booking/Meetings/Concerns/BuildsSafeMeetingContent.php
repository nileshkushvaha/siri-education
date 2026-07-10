<?php

declare(strict_types=1);

namespace App\Booking\Meetings\Concerns;

use App\Models\Booking;

/**
 * The one place that decides what a booking looks like on an external
 * meeting platform (Google event summary/description, Zoom topic/
 * agenda): booking reference, subject, and duration only — never
 * price, wallet data, payment provider ids, or internal metadata.
 */
trait BuildsSafeMeetingContent
{
    private function safeTitle(Booking $booking): string
    {
        $subject = $booking->meta['subject'] ?? null;

        return $subject !== null
            ? sprintf('Lesson: %s', str_replace(['_', '-'], ' ', (string) $subject))
            : 'Lesson';
    }

    private function safeDescription(Booking $booking): string
    {
        return sprintf(
            "Booking reference: %s\nDuration: %d minutes",
            $booking->reference,
            (int) $booking->starts_at->diffInMinutes($booking->ends_at),
        );
    }
}
