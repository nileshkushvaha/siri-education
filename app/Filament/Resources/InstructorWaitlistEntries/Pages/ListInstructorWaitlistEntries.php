<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorWaitlistEntries\Pages;

use App\Enums\WaitlistEntryStatus;
use App\Filament\Resources\InstructorWaitlistEntries\InstructorWaitlistEntryResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\InstructorWaitlistEntry;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListInstructorWaitlistEntries extends ListRecords
{
    protected static string $resource = InstructorWaitlistEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(InstructorWaitlistEntry::class, WaitlistEntryStatus::class);
    }
}
