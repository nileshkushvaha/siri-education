<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherLeave\Pages;

use App\Filament\Resources\TeacherLeave\TeacherLeaveResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTeacherLeave extends ListRecords
{
    protected static string $resource = TeacherLeaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
