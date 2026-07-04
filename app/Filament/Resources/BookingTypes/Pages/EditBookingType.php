<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingTypes\Pages;

use App\Filament\Resources\BookingTypes\BookingTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBookingType extends EditRecord
{
    protected static string $resource = BookingTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
