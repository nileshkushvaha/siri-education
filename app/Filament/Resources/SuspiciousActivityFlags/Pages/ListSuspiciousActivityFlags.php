<?php

declare(strict_types=1);

namespace App\Filament\Resources\SuspiciousActivityFlags\Pages;

use App\Compliance\Enums\SuspiciousActivityFlagStatus;
use App\Filament\Resources\SuspiciousActivityFlags\SuspiciousActivityFlagResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\SuspiciousActivityFlag;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListSuspiciousActivityFlags extends ListRecords
{
    protected static string $resource = SuspiciousActivityFlagResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(SuspiciousActivityFlag::class, SuspiciousActivityFlagStatus::class);
    }
}
