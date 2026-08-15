<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\AcademicLevelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAcademicLevels extends ListRecords
{
    protected static string $resource = AcademicLevelResource::class;

    public function getSubheading(): ?string
    {
        return 'Manage the education stages and grade ranges instructors can teach and students can study.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
