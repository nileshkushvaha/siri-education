<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\Academic\AcademicCategoryResource;
use App\Filament\Resources\Academic\SubjectResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAcademicCategories extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = AcademicCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...array_filter([BackAction::toResourceIndex(SubjectResource::class, 'Back to Subjects')]),
            CreateAction::make(),
        ];
    }

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::subjects();
    }
}
