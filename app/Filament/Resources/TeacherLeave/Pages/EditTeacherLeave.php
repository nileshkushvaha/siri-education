<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherLeave\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\TeacherLeave\TeacherLeaveResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\TeacherUnavailability;
use App\Services\Instructor\InstructorTimeOffService;
use App\Support\AvailabilityImpactConfirmation;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditTeacherLeave extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = TeacherLeaveResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $applied = AvailabilityImpactConfirmation::run(
            'leave-update:'.$record->getKey(),
            fn (?string $impactConfirmation) => app(InstructorTimeOffService::class)->update($record, $data, auth()->user(), $impactConfirmation),
        );

        if (! $applied) {
            throw new Halt;
        }

        return $record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Teacher Leave'),
            DeleteAction::make()
                ->using(fn (TeacherUnavailability $record) => app(InstructorTimeOffService::class)->delete($record, auth()->user())),
        ]);
    }
}
