<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherAvailability\Pages;

use App\Filament\Resources\TeacherAvailability\TeacherAvailabilityResource;
use App\Services\Instructor\InstructorAvailabilityService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTeacherAvailability extends CreateRecord
{
    protected static string $resource = TeacherAvailabilityResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(InstructorAvailabilityService::class)->create($data, auth()->user());
    }
}
