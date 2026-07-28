<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherAvailability\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\TeacherAvailability\TeacherAvailabilityResource;
use App\Filament\Support\Presentation\BackAction;
use App\Services\Instructor\InstructorAvailabilityService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTeacherAvailability extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = TeacherAvailabilityResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Teacher Availability'),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(InstructorAvailabilityService::class)->create($data, auth()->user());
    }
}
