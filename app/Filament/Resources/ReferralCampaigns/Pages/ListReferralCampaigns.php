<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralCampaigns\Pages;

use App\Filament\Resources\ReferralCampaigns\ReferralCampaignResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\ReferralCampaign;
use App\Referral\Enums\ReferralCampaignStatus;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListReferralCampaigns extends ListRecords
{
    protected static string $resource = ReferralCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(ReferralCampaign::class, ReferralCampaignStatus::class);
    }
}
