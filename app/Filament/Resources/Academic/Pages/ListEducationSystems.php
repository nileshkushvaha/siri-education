<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\EducationSystemResource;
use App\Filament\Support\Tables\AcademicStatusTabs;
use App\Models\EducationSystem;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

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

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return AcademicStatusTabs::make(EducationSystem::class);
    }
}
