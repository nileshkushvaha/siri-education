<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditCampaigns\Pages;

use App\Filament\Resources\PromotionalCreditCampaigns\PromotionalCreditCampaignResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\PromotionalCreditCampaign;
use App\PromotionalCredits\Enums\PromotionalCreditCampaignStatus;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListPromotionalCreditCampaigns extends ListRecords
{
    protected static string $resource = PromotionalCreditCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(PromotionalCreditCampaign::class, PromotionalCreditCampaignStatus::class);
    }
}
