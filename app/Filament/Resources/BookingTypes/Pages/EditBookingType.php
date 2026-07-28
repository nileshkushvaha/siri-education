<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingTypes\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\BookingTypes\BookingTypeResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBookingType extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = BookingTypeResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Booking Types'),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ]);
    }
}
