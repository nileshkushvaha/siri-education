<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralRewards\Pages;

use App\Filament\Resources\ReferralRewards\ReferralRewardResource;
use Filament\Resources\Pages\ListRecords;

class ListReferralRewards extends ListRecords
{
    protected static string $resource = ReferralRewardResource::class;
}
