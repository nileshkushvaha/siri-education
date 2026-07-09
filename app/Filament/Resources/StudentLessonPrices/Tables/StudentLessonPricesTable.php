<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLessonPrices\Tables;

use App\Enums\InstructorStatus;
use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentLessonPricesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bookingType.name')
                    ->label('Booking type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->placeholder('All instructors (base price)')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject.name')
                    ->label('Subject')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('academicLevel.name')
                    ->label('Level')
                    ->placeholder('All levels')
                    ->sortable(),
                TextColumn::make('country.name')
                    ->label('Country')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('duration_minutes')
                    ->label('Duration')
                    ->suffix(' min')
                    ->sortable(),
                TextColumn::make('amount_display')
                    ->label('Amount')
                    ->state(fn (StudentLessonPrice $record): string => number_format($record->amountDecimal(), 2).' '.$record->currency_code)
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('amount_minor', $direction)),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('priority')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('effective_from')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('effective_until')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('booking_type_id')
                    ->label('Booking type')
                    ->options(fn () => BookingType::query()->where('is_paid', true)->pluck('name', 'id')->all()),
                SelectFilter::make('instructor_id')
                    ->label('Instructor')
                    ->options(fn () => User::query()
                        ->whereHas('profile', fn (Builder $q) => $q->whereIn('instructor_status', InstructorStatus::bookable()))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
                SelectFilter::make('subject_id')
                    ->label('Subject')
                    ->options(fn () => Subject::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('academic_level_id')
                    ->label('Academic level')
                    ->options(fn () => AcademicLevel::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('country_id')
                    ->label('Country')
                    ->options(fn () => Country::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                SelectFilter::make('currency_id')
                    ->label('Currency')
                    ->options(fn () => Currency::query()->orderBy('code')->pluck('code', 'id')->all()),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
