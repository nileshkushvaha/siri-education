<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLessonPrices\Tables;

use App\Enums\InstructorStatus;
use App\Filament\Resources\StudentLessonPrices\Pages\EditStudentLessonPrice;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pricing matrix list. Every match dimension has its own always-visible
 * filter (instead of one crowded dropdown) so an admin can narrow the
 * matrix to a single booking type / subject / country in one click, and
 * rows can be grouped by any dimension to review a whole slice at once.
 */
class StudentLessonPricesTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('scope')
                    ->label('Scope')
                    ->badge()
                    ->state(fn (StudentLessonPrice $record): string => $record->instructor_id ? 'Instructor override' : 'Base price')
                    ->color(fn (string $state): string => $state === 'Base price' ? 'gray' : 'info')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw('instructor_id is null '.($direction === 'asc' ? 'desc' : 'asc'))),
                TextColumn::make('bookingType.name')
                    ->label('Booking type')
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
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->placeholder('All instructors')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('country.name')
                    ->label('Country')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('duration_minutes')
                    ->label('Duration')
                    ->suffix(' min')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('amount_display')
                    ->label('Student pays')
                    ->state(fn (StudentLessonPrice $record): string => number_format($record->amountDecimal(), 2).' '.$record->currency_code)
                    ->weight('semibold')
                    ->alignEnd()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('amount_minor', $direction)),
                TextColumn::make('effective_status')
                    ->label('Effective')
                    ->badge()
                    ->state(fn (StudentLessonPrice $record): string => self::effectiveStatus($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Current' => 'success',
                        'Scheduled' => 'warning',
                        'Expired' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (StudentLessonPrice $record): ?string => self::effectiveWindow($record)),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->disabled(fn (StudentLessonPrice $record): bool => $record->trashed() || ! (auth()->user()?->can('update', $record) ?? false))
                    ->afterStateUpdated(fn () => Notification::make()->title('Price updated')->success()->send()),
                TextColumn::make('priority')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('effective_from')
                    ->label('Starts')
                    ->dateTime()
                    ->placeholder('Immediately')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('effective_until')
                    ->label('Ends')
                    ->dateTime()
                    ->placeholder('No expiry')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('booking_type_id')
                    ->label('Booking type')
                    ->options(fn () => BookingType::query()->where('is_paid', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('subject_id')
                    ->label('Subject')
                    ->options(fn () => Subject::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('academic_level_id')
                    ->label('Academic level')
                    ->options(fn () => ['__all__' => 'All levels (no level set)'] + AcademicLevel::query()->orderBy('display_order')->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        '__all__' => $query->whereNull('academic_level_id'),
                        default => $query->where('academic_level_id', $data['value']),
                    }),
                SelectFilter::make('country_id')
                    ->label('Country')
                    ->options(fn () => Country::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('scope')
                    ->label('Scope')
                    ->options([
                        'base' => 'Base prices only',
                        'override' => 'Instructor overrides only',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'base' => $query->whereNull('instructor_id'),
                        'override' => $query->whereNotNull('instructor_id'),
                        default => $query,
                    }),
                SelectFilter::make('instructor_id')
                    ->label('Instructor')
                    ->options(fn () => User::query()
                        ->whereHas('profile', fn (Builder $q) => $q->whereIn('instructor_status', InstructorStatus::bookable()))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('duration_minutes')
                    ->label('Duration')
                    ->options(fn () => StudentLessonPrice::query()
                        ->withTrashed()
                        ->distinct()
                        ->orderBy('duration_minutes')
                        ->pluck('duration_minutes')
                        ->mapWithKeys(fn (int $minutes): array => [$minutes => $minutes.' min'])
                        ->all()),
                SelectFilter::make('currency_id')
                    ->label('Currency')
                    ->options(fn () => Currency::query()->orderBy('code')->pluck('code', 'id')->all()),
                SelectFilter::make('effective')
                    ->label('Effective')
                    ->options([
                        'current' => 'Current',
                        'scheduled' => 'Scheduled',
                        'expired' => 'Expired',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'current' => $query
                            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
                            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', now())),
                        'scheduled' => $query->where('effective_from', '>', now()),
                        'expired' => $query->where('effective_until', '<', now()),
                        default => $query,
                    }),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                TrashedFilter::make(),
            ])
            ->groups([
                Group::make('bookingType.name')->label('Booking type')->collapsible(),
                Group::make('subject.name')->label('Subject')->collapsible(),
                Group::make('country.name')->label('Country')->collapsible(),
                Group::make('instructor.name')->label('Instructor')->getTitleFromRecordUsing(fn (StudentLessonPrice $record): string => $record->instructor?->name ?? 'All instructors (base prices)')->collapsible(),
            ])
            ->emptyStateHeading('No lesson prices yet')
            ->emptyStateDescription('Add a base price for each booking type, subject, country and duration students can book. Instructor overrides are optional.')
            ->recordActions([
                EditAction::make(),
                ReplicateAction::make()
                    ->label('Duplicate')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->modalHeading('Duplicate this price')
                    ->modalDescription('The copy is created inactive so it never clashes with this row. Adjust its criteria or amount, then activate it.')
                    ->excludeAttributes(['created_by', 'updated_by'])
                    ->mutateRecordDataUsing(fn (array $data): array => [...$data, 'is_active' => false])
                    ->successNotificationTitle('Price duplicated — review and activate it')
                    ->successRedirectUrl(fn (StudentLessonPrice $replica): string => EditStudentLessonPrice::getUrl(['record' => $replica])),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->label('Activate')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(fn (StudentLessonPrice $record) => $record->update(['is_active' => true])))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->label('Deactivate')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(fn (StudentLessonPrice $record) => $record->update(['is_active' => false])))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');

        return AdminListTable::apply($table, 'Search booking type, subject, instructor, country');
    }

    private static function effectiveStatus(StudentLessonPrice $record): string
    {
        if ($record->effective_from !== null && $record->effective_from->isFuture()) {
            return 'Scheduled';
        }

        if ($record->effective_until !== null && $record->effective_until->isPast()) {
            return 'Expired';
        }

        return 'Current';
    }

    private static function effectiveWindow(StudentLessonPrice $record): ?string
    {
        if ($record->effective_from === null && $record->effective_until === null) {
            return 'Always in effect';
        }

        $from = $record->effective_from?->format('d M Y H:i') ?? 'immediately';
        $until = $record->effective_until?->format('d M Y H:i') ?? 'no expiry';

        return "From {$from} until {$until}";
    }
}
