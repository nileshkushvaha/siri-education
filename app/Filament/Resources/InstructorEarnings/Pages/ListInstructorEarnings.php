<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorEarnings\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\InstructorEarnings\InstructorEarningResource;
use App\Filament\Support\RelatedResourceLinkGroups;
use Filament\Resources\Pages\ListRecords;

class ListInstructorEarnings extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = InstructorEarningResource::class;

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::instructorFinance();
    }
}
