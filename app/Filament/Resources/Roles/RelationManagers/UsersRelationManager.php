<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\RelationManagers;

use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Users with this Role';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedUsers;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(
                        fn ($record) => 'https://ui-avatars.com/api/?name='.urlencode($record->name).'&color=ffffff&background=6366f1'
                    )
                    ->size(36),

                TextColumn::make('name')
                    ->label('Name')
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No users assigned')
            ->emptyStateDescription('Assign this role to users from the User Management screen.');
    }
}
