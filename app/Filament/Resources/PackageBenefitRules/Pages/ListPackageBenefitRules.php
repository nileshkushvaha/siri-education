<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackageBenefitRules\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\PackageBenefitRules\PackageBenefitRuleResource;
use App\Filament\Support\RelatedResourceLinkGroups;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPackageBenefitRules extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = PackageBenefitRuleResource::class;

    public function getSubheading(): ?string
    {
        return 'Create reusable lesson package templates, then use the related views to review proposals, payments, and student lesson balances.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::packages();
    }
}
