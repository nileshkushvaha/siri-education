<?php

declare(strict_types=1);

namespace App\Reviews\Enums;

/** Which pricing policy governed the lesson — drives which review policy setting applies. */
enum ReviewableLessonType: string
{
    case Paid = 'paid';
    case Demo = 'demo';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Demo => 'Demo',
        };
    }
}
