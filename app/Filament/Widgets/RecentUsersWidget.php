<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Actions\Action as TableAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentUsersWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('View:RecentUsersWidget');
    }

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Recent Registrations';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                User::query()
                    ->with(['roles', 'profile'])
                    ->latest()
                    ->limit(8)
            )
            ->columns([
                ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn (User $record) => 'https://ui-avatars.com/api/?name='.urlencode($record->name).'&background=6366f1&color=fff&size=40')
                    ->size(36),

                TextColumn::make('name')
                    ->label('User')
                    ->description(fn (User $record) => $record->email)
                    ->searchable()
                    ->weight(FontWeight::Medium),

                TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->separator(',')
                    ->color('warning'),

                BadgeColumn::make('status')
                    ->color(fn ($state): string => User::statusColor($state))
                    ->formatStateUsing(fn ($state): string => User::statusLabel($state)),

                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->since()
                    ->color('gray'),
            ])
            ->actions([
                TableAction::make('view')
                    ->url(fn (User $record) => route('filament.admin.resources.users.view', $record))
                    ->icon('heroicon-m-eye')
                    ->size('sm'),
            ])
            ->paginated(false)
            ->striped();
    }
}
