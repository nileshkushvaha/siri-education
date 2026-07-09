<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLessonPrices\Schemas;

use App\Enums\InstructorStatus;
use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\User;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * The student-facing amount is edited as a plain decimal (`amount`) —
 * it is not a model attribute; CreateStudentLessonPrice/EditStudentLessonPrice
 * convert it to/from `amount_minor` (integer, the only value ever
 * persisted or read for money) using the selected currency's
 * `minor_units`. Never add an `amount_minor` field directly to this
 * form — an admin must never type minor units by hand.
 */
class StudentLessonPriceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Match criteria')
                    ->description('Who this price applies to — the resolver picks the most specific active row that matches all of these.')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('booking_type_id')
                                ->label('Booking type')
                                ->options(fn () => BookingType::query()->active()->where('is_paid', true)->ordered()->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    $duration = $state !== null ? BookingType::query()->find($state)?->duration_minutes : null;

                                    if ($duration !== null) {
                                        $set('duration_minutes', $duration);
                                    }
                                })
                                ->helperText('Only paid booking types are listed — a demo/free type never needs a price.'),
                            Select::make('subject_id')
                                ->label('Subject')
                                ->options(fn () => Subject::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload()
                                ->required(),
                        ]),
                        Select::make('instructor_id')
                            ->label('Instructor Override')
                            ->options(fn () => User::query()
                                ->whereHas('profile', fn (Builder $q) => $q->whereIn('instructor_status', InstructorStatus::bookable()))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Leave blank to apply this price to all instructors. Select an instructor only for a special student-facing price override.'),
                        Grid::make(2)->schema([
                            Select::make('academic_level_id')
                                ->label('Academic level')
                                ->options(fn () => AcademicLevel::query()->active()->orderBy('display_order')->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload()
                                ->helperText('Leave empty to apply to every academic level.'),
                            TextInput::make('duration_minutes')
                                ->label('Duration')
                                ->numeric()
                                ->required()
                                ->minValue(5)
                                ->maxValue(600)
                                ->suffix('min')
                                // Two active rows matching the exact same
                                // criteria would make resolution ambiguous
                                // (priority is a tie-breaker for edge cases,
                                // not an invitation to create duplicates).
                                ->rule(function (Get $get, ?StudentLessonPrice $record): Closure {
                                    return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                        if (! $get('is_active')) {
                                            return;
                                        }

                                        $exists = StudentLessonPrice::query()
                                            ->where('booking_type_id', $get('booking_type_id'))
                                            ->where('subject_id', $get('subject_id'))
                                            ->where('academic_level_id', $get('academic_level_id'))
                                            ->where('country_id', $get('country_id'))
                                            ->where('duration_minutes', $value)
                                            ->where('instructor_id', $get('instructor_id'))
                                            ->where('is_active', true)
                                            ->when($record !== null, fn ($q) => $q->whereKeyNot($record->id))
                                            ->exists();

                                        if ($exists) {
                                            $fail($get('instructor_id')
                                                ? 'An active price already exists for this exact instructor, booking type, subject, academic level, country, and duration.'
                                                : 'An active base price already exists for this exact booking type, subject, academic level, country, and duration.');
                                        }
                                    };
                                }),
                        ]),
                        Grid::make(2)->schema([
                            Select::make('country_id')
                                ->label('Country')
                                ->options(fn () => Country::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('currency_id')
                                ->label('Currency')
                                ->options(fn () => Currency::query()->active()->orderBy('code')->pluck('code', 'id')->all())
                                ->searchable()
                                ->preload()
                                ->required(),
                        ]),
                    ]),

                Section::make('Price')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('amount')
                                ->label('Student pays')
                                ->numeric()
                                ->minValue(0.01)
                                ->required()
                                ->prefix(fn (Get $get): ?string => Currency::query()->find($get('currency_id'))?->symbol)
                                ->helperText('The full amount the student is charged — never negative or zero.'),
                            Toggle::make('is_active')
                                ->label('Active')
                                ->default(true)
                                ->helperText('Only active rows are ever matched by the resolver.'),
                            TextInput::make('priority')
                                ->numeric()
                                ->default(0)
                                ->helperText('Tie-breaker when more than one active row matches — higher wins.'),
                        ]),
                        Grid::make(2)->schema([
                            DateTimePicker::make('effective_from')
                                ->helperText('Leave empty to apply immediately.'),
                            DateTimePicker::make('effective_until')
                                ->helperText('Leave empty for no expiry.')
                                ->after('effective_from'),
                        ]),
                    ]),
            ]);
    }
}
