<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\EducationSystemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEducationSystems extends ListRecords
{
    protected static string $resource = EducationSystemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
