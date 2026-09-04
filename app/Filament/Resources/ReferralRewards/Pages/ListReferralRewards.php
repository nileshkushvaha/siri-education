<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralRewards\Pages;

use App\Filament\Resources\ReferralRewards\ReferralRewardResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\ReferralReward;
use App\Referral\Enums\ReferralRewardStatus;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListReferralRewards extends ListRecords
{
    protected static string $resource = ReferralRewardResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(ReferralReward::class, ReferralRewardStatus::class);
    }
}
