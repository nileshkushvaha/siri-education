<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherAvailability\Schemas;

use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
use DateTimeZone;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class TeacherAvailabilityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Weekly window')
                    ->description('Recurring working hours. Times are entered in the instructor timezone and converted safely when slots are generated.')
                    ->columnSpanFull()
                    ->schema([
                        // Who + when — a teacher's availability is always tied to one weekday.
                        Grid::make(2)->schema([
                            Select::make('teacher_id')
                                ->label('Teacher')
                                ->relationship(
                                    'teacher',
                                    'name',
                                    fn (Builder $query) => $query->whereHas('profile', fn (Builder $q) => $q->whereIn(
                                        'instructor_status',
                                        InstructorStatus::bookable(),
                                    )),
                                )
                                ->searchable()
                                ->preload()
                                ->required()
                                ->placeholder('Select a teacher'),
                            Select::make('day_of_week')
                                ->options(collect(Weekday::cases())
                                    ->mapWithKeys(fn (Weekday $d) => [$d->value => $d->label()])
                                    ->toArray())
                                ->required()
                                ->placeholder('Select a day'),
                        ]),

                        // The working window itself.
                        Grid::make(2)->schema([
                            TimePicker::make('start_time')
                                ->seconds(false)
                                ->required()
                                ->placeholder('e.g. 09:00'),
                            TimePicker::make('end_time')
                                ->seconds(false)
                                ->required()
                                ->after('start_time')
                                ->placeholder('e.g. 17:00'),
                        ]),

                        Select::make('timezone')
                            ->options(fn () => collect(DateTimeZone::listIdentifiers())->mapWithKeys(fn (string $timezone): array => [$timezone => $timezone])->all())
                            ->searchable()
                            ->placeholder('Defaults to the instructor profile timezone')
                            ->helperText('Defaults to the instructor profile timezone. Required for reliable daylight-saving handling.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Validity')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            DatePicker::make('effective_from')
                                ->placeholder('Immediately')
                                ->helperText('Empty = active immediately.'),
                            DatePicker::make('effective_until')
                                ->afterOrEqual('effective_from')
                                ->placeholder('No end date')
                                ->helperText('Empty = no end date.'),
                            Toggle::make('is_active')
                                ->default(true)
                                ->inline(false),
                        ]),
                    ]),
            ]);
    }
}
