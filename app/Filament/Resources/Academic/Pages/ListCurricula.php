<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\CurriculumResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCurricula extends ListRecords
{
    protected static string $resource = CurriculumResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
