<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherAvailability\Pages;

use App\Filament\Resources\TeacherAvailability\TeacherAvailabilityResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTeacherAvailability extends CreateRecord
{
    protected static string $resource = TeacherAvailabilityResource::class;
}
