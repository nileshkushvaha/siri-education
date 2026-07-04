<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingTypes\Pages;

use App\Filament\Resources\BookingTypes\BookingTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBookingTypes extends ListRecords
{
    protected static string $resource = BookingTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
