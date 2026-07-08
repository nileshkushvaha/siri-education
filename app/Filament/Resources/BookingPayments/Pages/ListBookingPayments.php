<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingPayments\Pages;

use App\Filament\Resources\BookingPayments\BookingPaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListBookingPayments extends ListRecords
{
    protected static string $resource = BookingPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
