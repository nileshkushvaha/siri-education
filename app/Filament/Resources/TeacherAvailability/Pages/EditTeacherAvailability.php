<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherAvailability\Pages;

use App\Filament\Resources\TeacherAvailability\TeacherAvailabilityResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTeacherAvailability extends EditRecord
{
    protected static string $resource = TeacherAvailabilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
