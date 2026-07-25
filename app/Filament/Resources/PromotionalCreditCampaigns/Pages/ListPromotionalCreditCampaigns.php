<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditCampaigns\Pages;

use App\Filament\Resources\PromotionalCreditCampaigns\PromotionalCreditCampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromotionalCreditCampaigns extends ListRecords
{
    protected static string $resource = PromotionalCreditCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
