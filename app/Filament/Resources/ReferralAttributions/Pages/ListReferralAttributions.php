<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralAttributions\Pages;

use App\Filament\Resources\ReferralAttributions\ReferralAttributionResource;
use Filament\Resources\Pages\ListRecords;

class ListReferralAttributions extends ListRecords
{
    protected static string $resource = ReferralAttributionResource::class;
}
