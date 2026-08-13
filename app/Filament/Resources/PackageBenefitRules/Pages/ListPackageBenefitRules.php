<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackageBenefitRules\Pages;

use App\Filament\Resources\PackageBenefitRules\PackageBenefitRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPackageBenefitRules extends ListRecords
{
    protected static string $resource = PackageBenefitRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
