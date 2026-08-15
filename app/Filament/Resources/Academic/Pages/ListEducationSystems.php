<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\EducationSystemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEducationSystems extends ListRecords
{
    protected static string $resource = EducationSystemResource::class;

    public function getSubheading(): ?string
    {
        return 'Configure each system in order: countries, broad academic levels, student-facing Classes/Grades/Years, then curricula.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
