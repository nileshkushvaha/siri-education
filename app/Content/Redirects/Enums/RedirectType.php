<?php

declare(strict_types=1);

namespace App\Content\Redirects\Enums;

enum RedirectType: string
{
    case Permanent = '301';
    case Temporary = '302';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => '301 Permanent',
            self::Temporary => '302 Temporary',
        };
    }

    public function httpStatus(): int
    {
        return (int) $this->value;
    }
}
