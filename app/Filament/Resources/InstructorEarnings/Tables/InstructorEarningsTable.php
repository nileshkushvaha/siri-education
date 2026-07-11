<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorEarnings\Tables;

use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Enums\EarningCalculationType;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Exceptions\EarningException;
use App\Models\InstructorEarning;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every lifecycle mutation routes through InstructorEarningService.
 * This is an admin-only surface — student_amount / platform_margin
 * columns are deliberately present here (admin may see them) while the
 * model hides them from every serialization an instructor could reach.
 */
class InstructorEarningsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_reference')
                    ->label('Booking')
                    ->state(fn (InstructorEarning $record): ?string => $record->metadata['booking_reference'] ?? null)
                    ->fontFamily('mono'),
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('subject.name')
                    ->label('Subject')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('earning_amount_minor')
                    ->label('Earning')
                    ->state(fn (InstructorEarning $record): string => self::money($record->earning_amount_minor, $record->currency_code))
                    ->sortable(),
                TextColumn::make('student_amount_minor')
                    ->label('Student Paid (admin)')
                    ->state(fn (InstructorEarning $record): string => $record->student_amount_minor !== null
                        ? self::money($record->student_amount_minor, $record->currency_code)
                        : '—')
                    ->toggleable(),
                TextColumn::make('platform_margin_minor')
                    ->label('Margin (admin)')
                    ->state(fn (InstructorEarning $record): string => $record->platform_margin_minor !== null
                        ? self::money($record->platform_margin_minor, $record->currency_code)
                        : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('calculation_type')
                    ->label('Rule')
                    ->formatStateUsing(fn (EarningCalculationType $state): string => $state->label())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InstructorEarningStatus $state): string => $state->label())
                    ->color(fn (InstructorEarningStatus $state): string => $state->color()),
                TextColumn::make('hold_until')
                    ->label('Hold Until')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('settlementBatch.batch_reference')
                    ->label('Batch')
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(InstructorEarningStatus::cases())
                        ->mapWithKeys(fn (InstructorEarningStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('instructor_id')
                    ->label('Instructor')
                    ->relationship('instructor', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('currency_code')
                    ->label('Currency')
                    ->options(fn (): array => InstructorEarning::query()
                        ->distinct()
                        ->pluck('currency_code', 'currency_code')
                        ->toArray()),
                Filter::make('created_between')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('release')
                    ->icon('heroicon-m-lock-open')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (InstructorEarning $record): bool => auth()->user()?->can('release', $record) ?? false)
                    ->visible(fn (InstructorEarning $record): bool => $record->status === InstructorEarningStatus::PendingHold)
                    ->action(fn (InstructorEarning $record) => self::callService(
                        // Admin release is the override path — it may release before the hold lapses.
                        fn (InstructorEarningServiceInterface $service) => $service->release($record, auth()->user(), override: true),
                        'Earning released',
                    )),

                Action::make('reverse')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(fn (InstructorEarning $record): bool => auth()->user()?->can('reverse', $record) ?? false)
                    ->visible(fn (InstructorEarning $record): bool => ! $record->status->isTerminal())
                    ->form([
                        Textarea::make('reason')->required()->maxLength(1000),
                    ])
                    ->action(fn (InstructorEarning $record, array $data) => self::callService(
                        fn (InstructorEarningServiceInterface $service) => $service->reverse($record, auth()->user(), $data['reason']),
                        'Earning reversed',
                    )),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** Admin display only — storage stays integer minor units. */
    private static function money(int $minor, string $currency): string
    {
        return sprintf('%s %s', number_format($minor / 100, 2), $currency);
    }

    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(InstructorEarningServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (EarningException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
