<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherAvailability\Pages;

use App\Filament\Resources\TeacherAvailability\TeacherAvailabilityResource;
use App\Models\TeacherAvailability;
use App\Services\Instructor\InstructorAvailabilityService;
use App\Support\AvailabilityImpactConfirmation;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditTeacherAvailability extends EditRecord
{
    protected static string $resource = TeacherAvailabilityResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $applied = AvailabilityImpactConfirmation::run(
            'availability-update:'.$record->getKey(),
            fn (?string $impactConfirmation) => app(InstructorAvailabilityService::class)->update($record, $data, auth()->user(), $impactConfirmation),
        );

        if (! $applied) {
            throw new Halt;
        }

        return $record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (TeacherAvailability $record): bool {
                    return AvailabilityImpactConfirmation::run(
                        'availability-delete:'.$record->getKey(),
                        fn (?string $impactConfirmation) => app(InstructorAvailabilityService::class)->delete($record, auth()->user(), $impactConfirmation),
                    );
                }),
        ];
    }
}
