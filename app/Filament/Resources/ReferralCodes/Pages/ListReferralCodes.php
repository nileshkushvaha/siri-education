<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralCodes\Pages;

use App\Filament\Resources\ReferralCodes\ReferralCodeResource;
use Filament\Resources\Pages\ListRecords;

class ListReferralCodes extends ListRecords
{
    protected static string $resource = ReferralCodeResource::class;
}
