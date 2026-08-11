<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\CurriculumVersionResource;
use Filament\Resources\Pages\ListRecords;

class ListCurriculumVersions extends ListRecords
{
    protected static string $resource = CurriculumVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
