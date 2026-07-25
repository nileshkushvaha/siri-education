<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalAlerts\Pages;

use App\Filament\Resources\OperationalAlerts\OperationalAlertResource;
use Filament\Resources\Pages\ViewRecord;

class ViewOperationalAlert extends ViewRecord
{
    protected static string $resource = OperationalAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
