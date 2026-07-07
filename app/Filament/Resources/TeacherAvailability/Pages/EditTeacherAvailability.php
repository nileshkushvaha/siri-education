<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherAvailability\Pages;

use App\Filament\Resources\TeacherAvailability\TeacherAvailabilityResource;
use App\Models\TeacherAvailability;
use App\Services\Instructor\InstructorAvailabilityService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTeacherAvailability extends EditRecord
{
    protected static string $resource = TeacherAvailabilityResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(InstructorAvailabilityService::class)->update($record, $data, auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (TeacherAvailability $record) => app(InstructorAvailabilityService::class)->delete($record, auth()->user())),
        ];
    }
}
