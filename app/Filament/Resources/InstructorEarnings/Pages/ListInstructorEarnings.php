<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorEarnings\Pages;

use App\Earnings\Enums\InstructorEarningStatus;
use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\InstructorEarnings\InstructorEarningResource;
use App\Filament\Support\RelatedResourceLinkGroups;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\InstructorEarning;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListInstructorEarnings extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = InstructorEarningResource::class;

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::instructorFinance();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(InstructorEarning::class, InstructorEarningStatus::class);
    }
}
