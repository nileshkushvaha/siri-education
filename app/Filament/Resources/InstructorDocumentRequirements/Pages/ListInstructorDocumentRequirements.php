<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorDocumentRequirements\Pages;

use App\Filament\Resources\InstructorDocumentRequirements\InstructorDocumentRequirementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInstructorDocumentRequirements extends ListRecords
{
    protected static string $resource = InstructorDocumentRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
