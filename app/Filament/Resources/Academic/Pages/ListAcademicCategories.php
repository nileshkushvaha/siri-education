<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\AcademicCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAcademicCategories extends ListRecords
{
    protected static string $resource = AcademicCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
