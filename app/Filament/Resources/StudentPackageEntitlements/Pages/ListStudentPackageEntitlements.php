<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentPackageEntitlements\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\PackageBenefitRules\PackageBenefitRuleResource;
use App\Filament\Resources\StudentPackageEntitlements\StudentPackageEntitlementResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use Filament\Resources\Pages\ListRecords;

class ListStudentPackageEntitlements extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = StudentPackageEntitlementResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(PackageBenefitRuleResource::class, 'Back to Lesson Packages'),
        ]);
    }

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::packages();
    }
}
