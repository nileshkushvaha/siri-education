<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conversations\Schemas;

use App\Messaging\Enums\ConversationStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ConversationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Conversation')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('student.name')->label('Student'),
                        TextEntry::make('instructor.name')->label('Instructor'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (ConversationStatus $state): string => $state->label())
                            ->color(fn (ConversationStatus $state): string => $state->color()),
                    ]),
                    Grid::make(2)->schema([
                        TextEntry::make('context_type')
                            ->label('Context Type')
                            ->formatStateUsing(fn (string $state): string => class_basename($state)),
                        TextEntry::make('context_id')->label('Context ID')->copyable(),
                    ]),
                    Grid::make(3)->schema([
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('last_message_at')->dateTime()->placeholder('—'),
                        TextEntry::make('closed_at')->dateTime()->placeholder('—'),
                    ]),
                ]),
        ]);
    }
}
