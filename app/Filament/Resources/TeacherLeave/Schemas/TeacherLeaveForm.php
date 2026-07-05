<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherLeave\Schemas;

use App\Enums\InstructorStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class TeacherLeaveForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Leave period')
                    ->description('Blocks all bookings for the teacher during this window (times in UTC).')
                    ->schema([
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
                            ->required(),
                        Grid::make(2)->schema([
                            DateTimePicker::make('starts_at')
                                ->seconds(false)
                                ->required(),
                            DateTimePicker::make('ends_at')
                                ->seconds(false)
                                ->required()
                                ->after('starts_at'),
                        ]),
                        TextInput::make('reason')
                            ->maxLength(255)
                            ->placeholder('Annual leave, sick day, training…'),
                    ]),
            ]);
    }
}
