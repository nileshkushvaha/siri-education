<?php

declare(strict_types=1);

namespace App\PromotionalCredits\Enums;

/** SRS §16.18 lists five sub-types; requirement #3 narrows V1 to these two. */
enum PromotionalCreditIssuanceType: string
{
    case Campaign = 'campaign';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Campaign => 'Campaign',
            self::Manual => 'Manual',
        };
    }
}
