<?php

declare(strict_types=1);

namespace App\Filament\Resources\SuspiciousActivityFlags\Pages;

use App\Filament\Resources\SuspiciousActivityFlags\SuspiciousActivityFlagResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSuspiciousActivityFlag extends ViewRecord
{
    protected static string $resource = SuspiciousActivityFlagResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
