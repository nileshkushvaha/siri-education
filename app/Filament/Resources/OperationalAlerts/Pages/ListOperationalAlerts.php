<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalAlerts\Pages;

use App\Alerts\Enums\OperationalAlertStatus;
use App\Filament\Resources\OperationalAlerts\OperationalAlertResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\OperationalAlert;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListOperationalAlerts extends ListRecords
{
    protected static string $resource = OperationalAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(OperationalAlert::class, OperationalAlertStatus::class);
    }
}
