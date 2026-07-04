<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherAvailability\Schemas;

use App\Booking\Enums\Weekday;
use App\Enums\InstructorStatus;
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
                    ->description('Recurring working hours. Times are stored in UTC.')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('teacher_id')
                                ->label('Teacher')
                                ->relationship(
                                    'teacher',
                                    'name',
                                    fn (Builder $query) => $query->whereHas('profile', fn (Builder $q) => $q->whereIn(
                                        'instructor_status',
                                        [InstructorStatus::Approved, InstructorStatus::Published],
                                    )),
                                )
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('day_of_week')
                                ->options(collect(Weekday::cases())
                                    ->mapWithKeys(fn (Weekday $d) => [$d->value => $d->label()])
                                    ->toArray())
                                ->required(),
                        ]),
                        Grid::make(2)->schema([
                            TimePicker::make('start_time')
                                ->seconds(false)
                                ->required(),
                            TimePicker::make('end_time')
                                ->seconds(false)
                                ->required()
                                ->after('start_time'),
                        ]),
                    ]),

                Section::make('Validity')
                    ->schema([
                        Grid::make(3)->schema([
                            DatePicker::make('effective_from')
                                ->helperText('Empty = active immediately.'),
                            DatePicker::make('effective_until')
                                ->afterOrEqual('effective_from')
                                ->helperText('Empty = no end date.'),
                            Toggle::make('is_active')
                                ->default(true)
                                ->inline(false),
                        ]),
                    ]),
            ]);
    }
}
