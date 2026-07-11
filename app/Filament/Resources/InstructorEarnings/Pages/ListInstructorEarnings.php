<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorEarnings\Pages;

use App\Filament\Resources\InstructorEarnings\InstructorEarningResource;
use Filament\Resources\Pages\ListRecords;

class ListInstructorEarnings extends ListRecords
{
    protected static string $resource = InstructorEarningResource::class;
}
