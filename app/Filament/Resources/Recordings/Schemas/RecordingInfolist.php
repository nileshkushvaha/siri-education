<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Schemas;

use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Enums\RecordingStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Operational visibility for admins. Deliberately shows the storage
 * BACKEND and never the locator: a Drive file id (or S3 key) on an
 * admin screen is an out-of-band pointer to private student video,
 * and it is not needed to diagnose anything. No credential, token, or
 * provider download URL appears here or anywhere in this resource.
 */
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
                            ->formatStateUsing(fn (?int $state): ?string => $state !== null ? gmdate($state >= 3600 ? 'H:i:s' : 'i:s', $state) : null)
                            ->placeholder('—'),
                        TextEntry::make('mime_type')->label('Format')->placeholder('—'),
                    ]),
                    Grid::make(3)->schema([
                        TextEntry::make('recorded_at')->dateTime()->placeholder('—'),
                        TextEntry::make('available_at')->dateTime()->placeholder('—'),
                        TextEntry::make('expires_at')->dateTime()->placeholder('—'),
                    ]),
                    Grid::make(3)->schema([
                        // The driver only — never storage_path.
                        TextEntry::make('storage_driver')
                            ->label('Storage backend')
                            ->placeholder('—'),
                        TextEntry::make('size_bytes')
                            ->label('Size')
                            ->formatStateUsing(fn (?int $state): ?string => $state !== null ? number_format($state / 1048576, 1).' MB' : null)
                            ->placeholder('—'),
                        TextEntry::make('stored_at')->dateTime()->placeholder('—'),
                    ]),
                    TextEntry::make('failure_code')
                        ->label('Failure')
                        // The stable label, never a raw exception message.
                        ->formatStateUsing(fn (RecordingFailureCode $state): string => $state->label())
                        ->placeholder('—'),
                    TextEntry::make('capture_attempts')->label('Attempts'),
                ]),
        ]);
    }
}
