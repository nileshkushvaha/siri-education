<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\AcademicLevelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAcademicLevels extends ListRecords
{
    protected static string $resource = AcademicLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
