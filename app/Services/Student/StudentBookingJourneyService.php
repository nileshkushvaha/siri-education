<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\Types\FreeDemoType;
use App\Booking\Types\PaidOneToOneType;
use App\Models\User;

final class StudentBookingJourneyService
{
    public function __construct(private readonly StudentBookingServiceInterface $bookings) {}

    /** @return array<string, mixed> */
    public function for(User $student): array
    {
        $journey = $this->bookings->bookingJourney($student);

        if (! $journey->has_bookings) {
            return [
                'eyebrow' => 'Your first step',
                'title' => 'Start with a free demo',
                'description' => 'Meet an instructor, share your learning goals, and see if they are the right fit. Your first demo with each instructor is free.',
                'primary_label' => 'Book a free demo',
                'header_label' => 'Book free demo',
                'primary_url' => route('booking.create', ['type' => FreeDemoType::KEY]),
            ];
        }

        if ($journey->has_completed_demo) {
            return [
                'eyebrow' => 'Keep learning',
                'title' => 'Continue with a paid lesson',
                'description' => 'Book your next paid lesson to continue with your instructor. You can still take one free demo with any instructor you have not tried before.',
                'primary_label' => 'Book a paid lesson',
                'header_label' => 'Book paid lesson',
                'primary_url' => route('booking.create', ['type' => PaidOneToOneType::KEY]),
                'secondary_label' => 'Try another instructor',
                'secondary_url' => route('booking.create', ['type' => FreeDemoType::KEY]),
            ];
        }

        return [
            'eyebrow' => 'Ready when you are',
            'title' => 'Plan your next learning session',
            'description' => 'Choose an instructor and a time that works for you.',
            'primary_label' => 'Book a lesson',
            'header_label' => 'Book a lesson',
            'primary_url' => route('booking.create'),
        ];
    }
}
