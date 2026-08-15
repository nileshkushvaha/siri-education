<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\AcademicLevelResource;
use App\Filament\Resources\Academic\SkillLevelResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSkillLevels extends ListRecords
{
    protected static string $resource = SkillLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...array_filter([BackAction::toResourceIndex(AcademicLevelResource::class, 'Back to Levels')]),
            CreateAction::make(),
        ];
    }
}
