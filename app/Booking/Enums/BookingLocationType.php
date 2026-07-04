<?php

declare(strict_types=1);

namespace App\Booking\Enums;

enum BookingLocationType: string
{
    case Online = 'online';
    case InPerson = 'in_person';
    case Phone = 'phone';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::InPerson => 'In Person',
            self::Phone => 'Phone',
        };
    }
}
