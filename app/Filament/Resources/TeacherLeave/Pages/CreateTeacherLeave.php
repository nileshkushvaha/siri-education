<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherLeave\Pages;

use App\Filament\Resources\TeacherLeave\TeacherLeaveResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTeacherLeave extends CreateRecord
{
    protected static string $resource = TeacherLeaveResource::class;
}
