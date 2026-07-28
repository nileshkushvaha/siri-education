<?php

namespace App\Filament\Resources\Currencies\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Resources\Pages\CreateRecord;

class CreateCurrency extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Currencies'),
        ]);
    }
}
