<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Pages;

use App\Booking\Enums\RecordingStatus;
use App\Filament\Resources\Recordings\RecordingResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\Recording;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListRecordings extends ListRecords
{
    protected static string $resource = RecordingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(Recording::class, RecordingStatus::class);
    }
}
