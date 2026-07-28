<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingTypes\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\BookingTypes\BookingTypeResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Resources\Pages\CreateRecord;

class CreateBookingType extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = BookingTypeResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Booking Types'),
        ]);
    }
}
