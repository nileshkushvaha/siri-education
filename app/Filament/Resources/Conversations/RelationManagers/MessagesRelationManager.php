<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conversations\RelationManagers;

use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Read-only message history (SRS §17.36 "Review communication history
 * for disputes" / "Search flagged messages").
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Message History';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedChatBubbleLeftRight;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sent_at')->dateTime()->sortable(),
                TextColumn::make('sender.name')->label('From'),
                TextColumn::make('body')->wrap()->limit(300),
                IconColumn::make('flagged_leakage')
                    ->label('Flagged for shared contact info')
                    ->boolean(),
                TextColumn::make('read_at')->dateTime()->placeholder('Unread'),
            ])
            ->filters([
                TernaryFilter::make('flagged_leakage')->label('Flagged for shared contact info'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->bulkActions([])
            ->defaultSort('sent_at', 'asc');
    }
}
