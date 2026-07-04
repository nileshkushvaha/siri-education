<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherLeave\Pages;

use App\Filament\Resources\TeacherLeave\TeacherLeaveResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTeacherLeave extends EditRecord
{
    protected static string $resource = TeacherLeaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
