<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Schemas;

use App\Models\Booking;
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
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            $readonly(TextInput::make('reference')),
                            $readonly(TextInput::make('type_name')->label('Type')
                                ->formatStateUsing(fn ($record): ?string => $record?->type?->name)),
                            $readonly(TextInput::make('status_label')->label('Status')
                                ->formatStateUsing(fn ($record): ?string => $record?->status?->label())),
                        ]),
                        Grid::make(2)->schema([
                            $readonly(TextInput::make('instructor_name')->label('Instructor')
                                ->formatStateUsing(fn ($record): ?string => $record?->instructor?->name)),
                            $readonly(TextInput::make('student_name')->label('Student')
                                ->formatStateUsing(fn ($record): ?string => $record?->student?->name)),
                        ]),
                        Grid::make(3)->schema([
                            $readonly(DateTimePicker::make('starts_at')),
                            $readonly(DateTimePicker::make('ends_at')),
                            $readonly(TextInput::make('timezone')),
                        ]),
                    ]),

                Section::make('Payment')
                    ->description('Snapshotted at booking time by BookingPriceCalculator — settlement happens through the payment workflow, never edited here.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            $readonly(TextInput::make('payment_status_label')->label('Payment status')
                                ->formatStateUsing(fn ($record): ?string => $record?->payment_status?->label())),
                            $readonly(TextInput::make('price')->label('Amount')
                                ->formatStateUsing(fn ($record): ?string => $record?->price !== null
                                    ? number_format((float) $record->price, 2).' '.$record->currency
                                    : 'Free')),
                            $readonly(TextInput::make('payment_reference')),
                        ]),
                    ]),

                Section::make('Meeting')
                    ->description('Read-only summary of the booking_meetings row — use the "Create/Update Meeting", "Retry Google Meet", and "Mark Meeting Cancelled" table actions to make changes, so eligibility, provider selection, and idempotency always run through BookingMeetingService.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            $readonly(TextInput::make('meeting_status_label')->label('Status')
                                ->formatStateUsing(fn (?Booking $record): ?string => $record?->meeting?->status?->label() ?? 'None')),
                            $readonly(TextInput::make('meeting_provider_label')->label('Provider')
                                ->formatStateUsing(fn (?Booking $record): ?string => $record?->meeting?->provider)),
                            $readonly(TextInput::make('meeting_failure_reason')->label('Failure reason')
                                ->formatStateUsing(fn (?Booking $record): ?string => $record?->meeting?->failure_reason)),
                        ]),
                        Grid::make(2)->schema([
                            $readonly(TextInput::make('meeting_join_url')->label('Join URL')
                                ->formatStateUsing(fn (?Booking $record): ?string => $record?->meeting?->status?->value === 'created' ? $record?->meeting?->join_url : null)),
                            $readonly(TextInput::make('meeting_updated_at')->label('Last updated')
                                ->formatStateUsing(fn (?Booking $record): ?string => $record?->meeting?->updated_at?->toDayDateTimeString())),
                        ]),
                    ]),

                Section::make('Notes')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('notes')->rows(3)->maxLength(1000)->placeholder('Internal admin notes…'),
                    ]),
            ]);
    }
}
