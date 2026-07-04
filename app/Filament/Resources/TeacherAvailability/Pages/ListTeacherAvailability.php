<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherAvailability\Pages;

use App\Filament\Resources\TeacherAvailability\TeacherAvailabilityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTeacherAvailability extends ListRecords
{
    protected static string $resource = TeacherAvailabilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
