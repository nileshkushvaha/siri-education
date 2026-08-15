<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\LoginHistory;
use App\Support\LoginHistoryColors;
use App\Support\Timezone\ViewerDateTime;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only — this user's own login history. Reuses the same column
 * patterns as LoginHistoryTable, scoped to a single user so the
 * per-record "User" column from that table isn't needed here.
 */
class LoginHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'loginHistories';

    protected static ?string $title = 'Login History';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClipboardDocumentList;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('logged_in_at', 'desc')
            ->columns([
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => LoginHistoryColors::forStatus($state))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->copyable(),

                TextColumn::make('browser')
                    ->label('Browser'),

                TextColumn::make('platform')
                    ->label('OS'),

                TextColumn::make('device_type')
                    ->label('Device')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('logged_in_at')
                    ->label('Login')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->since()
                    ->tooltip(fn (LoginHistory $record): string => ViewerDateTime::labelled($record->logged_in_at, format: 'Y-m-d H:i:s') ?? ''),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'locked' => 'Locked',
                        'blocked' => 'Blocked',
                        'unverified' => 'Unverified',
                    ])
                    ->placeholder('All statuses'),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No login history yet');
    }
}
