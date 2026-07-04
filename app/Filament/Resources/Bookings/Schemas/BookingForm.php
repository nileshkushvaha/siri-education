<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Bookings are engine-managed: scheduling fields are read-only here.
 * Status/time changes go through the table's lifecycle actions so
 * validation, locking, events, and notifications always run.
 */
class BookingForm
{
    public static function configure(Schema $schema): Schema
    {
        $readonly = fn (TextInput|DateTimePicker $field) => $field->disabled()->dehydrated(false);

        return $schema
            ->components([
                Section::make('Booking')
                    ->schema([
                        Grid::make(3)->schema([
                            $readonly(TextInput::make('reference')),
                            $readonly(TextInput::make('type_name')->label('Type')
                                ->formatStateUsing(fn ($record): ?string => $record?->type?->name)),
                            $readonly(TextInput::make('status_label')->label('Status')
                                ->formatStateUsing(fn ($record): ?string => $record?->status?->label())),
                        ]),
                        Grid::make(3)->schema([
                            $readonly(TextInput::make('host_name')->label('Teacher')
                                ->formatStateUsing(fn ($record): ?string => $record?->host?->name)),
                            $readonly(TextInput::make('attendee_name')->label('Attendee')
                                ->formatStateUsing(fn ($record): ?string => $record?->attendeeName())),
                            $readonly(TextInput::make('guest_email')->label('Guest email')),
                        ]),
                        Grid::make(3)->schema([
                            $readonly(DateTimePicker::make('starts_at')),
                            $readonly(DateTimePicker::make('ends_at')),
                            $readonly(TextInput::make('timezone')),
                        ]),
                    ]),

                Section::make('Meeting')
                    ->description('Provider linkage — filled by the meeting integration or manually.')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('meeting_provider')->maxLength(50),
                            TextInput::make('meeting_ref')->maxLength(255),
                            TextInput::make('meeting_url')->url()->maxLength(2048),
                        ]),
                    ]),

                Section::make('Notes')
                    ->schema([
                        Textarea::make('notes')->rows(3)->maxLength(1000),
                    ]),
            ]);
    }
}
