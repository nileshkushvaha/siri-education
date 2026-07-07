<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherLeave\Pages;

use App\Filament\Resources\TeacherLeave\TeacherLeaveResource;
use App\Services\Instructor\InstructorTimeOffService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTeacherLeave extends CreateRecord
{
    protected static string $resource = TeacherLeaveResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(InstructorTimeOffService::class)->create($data, auth()->user());
    }
}
