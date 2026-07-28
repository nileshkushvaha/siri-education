<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Schemas;

use App\Booking\Enums\RecordingStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RecordingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Details')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('booking.reference')->label('Booking'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (RecordingStatus $state): string => $state->label())
                            ->color(fn (RecordingStatus $state): string => $state->color()),
                    ]),
                    Grid::make(2)->schema([
                        TextEntry::make('student.name')->label('Student'),
                        TextEntry::make('teacher.name')->label('Instructor'),
                    ]),
                    Grid::make(3)->schema([
                        TextEntry::make('provider'),
                        TextEntry::make('duration_seconds')
                            ->label('Duration')
                            ->state(fn (?int $state): ?string => $state !== null ? gmdate($state >= 3600 ? 'H:i:s' : 'i:s', $state) : null)
                            ->placeholder('—'),
                        TextEntry::make('mime_type')->label('Format')->placeholder('—'),
                    ]),
                    Grid::make(3)->schema([
                        TextEntry::make('recorded_at')->dateTime()->placeholder('—'),
                        TextEntry::make('available_at')->dateTime()->placeholder('—'),
                        TextEntry::make('expires_at')->dateTime()->placeholder('—'),
                    ]),
                    TextEntry::make('failure_code')->placeholder('—'),
                ]),
        ]);
    }
}
