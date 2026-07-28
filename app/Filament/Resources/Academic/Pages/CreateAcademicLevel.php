<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Academic\AcademicLevelResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Resources\Pages\CreateRecord;

class CreateAcademicLevel extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = AcademicLevelResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Academic Levels'),
        ]);
    }
}
