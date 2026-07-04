<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Pages;

use App\Booking\Enums\BookingStatus;
use App\Filament\Resources\Bookings\BookingResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending' => Tab::make('Pending approval')
                ->modifyQueryUsing(fn (Builder $query) => $query->withStatus(BookingStatus::Pending)),
            'upcoming' => Tab::make('Upcoming')
                ->modifyQueryUsing(fn (Builder $query) => $query->active()->upcoming()),
            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->withStatus(BookingStatus::Completed)),
            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query) => $query->withStatus(BookingStatus::Cancelled)),
        ];
    }
}
