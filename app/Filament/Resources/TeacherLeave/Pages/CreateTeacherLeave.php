<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherLeave\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\TeacherLeave\TeacherLeaveResource;
use App\Filament\Support\Presentation\BackAction;
use App\Services\Instructor\InstructorTimeOffService;
use App\Support\AvailabilityImpactConfirmation;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateTeacherLeave extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = TeacherLeaveResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Teacher Leave'),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $leave = null;

        $applied = AvailabilityImpactConfirmation::run(
            'leave-create:'.auth()->id(),
            function (?string $impactConfirmation) use ($data, &$leave): void {
                $leave = app(InstructorTimeOffService::class)->create($data, auth()->user(), $impactConfirmation);
            },
        );

        if (! $applied || $leave === null) {
            throw new Halt;
        }

        return $leave;
    }
}
