<?php

declare(strict_types=1);

namespace App\Referral\Enums;

/**
 * How the referred student supplied the code at registration: via the
 * shareable `?ref=` link, or typed manually into the optional field.
 * Informational only — both paths run the identical validation and
 * attribution rules.
 */
enum ReferralAttributionSource: string
{
    case Link = 'link';
    case Manual = 'manual';
}
