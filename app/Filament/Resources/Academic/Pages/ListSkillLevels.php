<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\SkillLevelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSkillLevels extends ListRecords
{
    protected static string $resource = SkillLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
