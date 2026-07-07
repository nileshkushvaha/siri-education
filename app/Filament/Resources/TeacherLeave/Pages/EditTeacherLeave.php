<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherLeave\Pages;

use App\Filament\Resources\TeacherLeave\TeacherLeaveResource;
use App\Models\TeacherUnavailability;
use App\Services\Instructor\InstructorTimeOffService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTeacherLeave extends EditRecord
{
    protected static string $resource = TeacherLeaveResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(InstructorTimeOffService::class)->update($record, $data, auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (TeacherUnavailability $record) => app(InstructorTimeOffService::class)->delete($record, auth()->user())),
        ];
    }
}
