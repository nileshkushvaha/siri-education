<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bookings\Schemas;

use App\Booking\Enums\BookingPaymentStatus;
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

                Section::make('Payment')
                    ->description('Snapshotted at booking time by BookingPriceCalculator — settlement happens through the payment workflow, never edited here.')
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
                    ->description('Provider linkage — filled by the meeting integration or manually. Disabled until payment is settled (or not required) so an unpaid booking can never carry a meeting link.')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('meeting_provider')->maxLength(50)->disabled(fn (?Booking $record): bool => ! self::paymentSettled($record)),
                            TextInput::make('meeting_ref')->maxLength(255)->disabled(fn (?Booking $record): bool => ! self::paymentSettled($record)),
                            TextInput::make('meeting_url')->url()->maxLength(2048)->disabled(fn (?Booking $record): bool => ! self::paymentSettled($record)),
                        ]),
                    ]),

                Section::make('Notes')
                    ->schema([
                        Textarea::make('notes')->rows(3)->maxLength(1000),
                    ]),
            ]);
    }

    private static function paymentSettled(?Booking $record): bool
    {
        return in_array($record?->payment_status, [BookingPaymentStatus::Paid, BookingPaymentStatus::NotRequired], true);
    }
}
