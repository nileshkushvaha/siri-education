<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorCompensationExceptions\Pages;

use App\Filament\Resources\InstructorCompensationExceptions\InstructorCompensationExceptionResource;
use Filament\Resources\Pages\ListRecords;

class ListInstructorCompensationExceptions extends ListRecords
{
    protected static string $resource = InstructorCompensationExceptionResource::class;
}
