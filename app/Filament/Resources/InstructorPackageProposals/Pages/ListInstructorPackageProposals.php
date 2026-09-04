<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPackageProposals\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\InstructorPackageProposals\InstructorPackageProposalResource;
use App\Filament\Resources\PackageBenefitRules\PackageBenefitRuleResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\InstructorPackageProposal;
use App\Package\Enums\InstructorPackageProposalStatus;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListInstructorPackageProposals extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = InstructorPackageProposalResource::class;

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

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(InstructorPackageProposal::class, InstructorPackageProposalStatus::class);
    }
}
