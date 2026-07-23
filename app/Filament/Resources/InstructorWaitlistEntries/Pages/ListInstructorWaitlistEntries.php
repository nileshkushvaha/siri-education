<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorWaitlistEntries\Pages;

use App\Filament\Resources\InstructorWaitlistEntries\InstructorWaitlistEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListInstructorWaitlistEntries extends ListRecords
{
    protected static string $resource = InstructorWaitlistEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
