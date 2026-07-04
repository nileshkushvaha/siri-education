<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Booking;

use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TopTeachersWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Booking::class) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Top teachers (last 30 days)')
            ->query(
                User::query()
                    ->withCount([
                        'hostedBookings as bookings_30d' => fn ($query) => $query
                            ->where('created_at', '>=', now()->subDays(30)),
                        'hostedBookings as completed_30d' => fn ($query) => $query
                            ->where('status', BookingStatus::Completed)
                            ->where('created_at', '>=', now()->subDays(30)),
                    ])
                    ->whereHas('hostedBookings', fn ($query) => $query->where('created_at', '>=', now()->subDays(30)))
                    ->orderByDesc('bookings_30d')
                    ->limit(10),
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Teacher'),
                TextColumn::make('bookings_30d')
                    ->label('Bookings'),
                TextColumn::make('completed_30d')
                    ->label('Completed'),
            ])
            ->paginated(false);
    }
}
