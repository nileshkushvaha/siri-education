<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutAttempts\Tables;

use App\Earnings\Contracts\InstructorPayoutExecutionServiceInterface;
use App\Earnings\Contracts\InstructorPayoutReconciliationServiceInterface;
use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\PayoutFailureCategory;
use App\Earnings\Exceptions\EarningException;
use App\Models\InstructorPayoutAttempt;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only financial record — no status select, no editing of
 * amount/destination, no delete, no manual "mark paid" action anywhere.
 * The only mutating actions are Cancel (pre-acceptance only) and
 * Reconcile Now, both of which delegate to the owning services.
 */
class InstructorPayoutAttemptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('withdrawalRequest.reference')
                    ->label('Withdrawal')
                    ->searchable(),
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('provider')
                    ->label('Provider')
                    ->badge(),
                TextColumn::make('execution_sequence')
                    ->label('Seq')
                    ->sortable(),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->state(fn (InstructorPayoutAttempt $record): string => MoneyFormatter::format($record->amount_minor, $record->currency_code))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InstructorPayoutAttemptStatus $state): string => $state->label())
                    ->color(fn (InstructorPayoutAttemptStatus $state): string => $state->color()),
                TextColumn::make('provider_status')
                    ->label('Provider Status')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failure_category')
                    ->label('Failure Category')
                    ->formatStateUsing(fn (?PayoutFailureCategory $state): ?string => $state?->label())
                    ->placeholder('—')
                    ->color('danger'),
                TextColumn::make('reconciliationIssues')
                    ->label('Open Issues')
                    ->state(fn (InstructorPayoutAttempt $record): int => $record->reconciliationIssues()->open()->count())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('initiator.name')
                    ->label('Queued By')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Queued At')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_synced_at')
                    ->label('Last Synced')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('attempt_count')
                    ->label('Attempts')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(InstructorPayoutAttemptStatus::cases())
                        ->mapWithKeys(fn (InstructorPayoutAttemptStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('provider')
                    ->options(fn (): array => InstructorPayoutAttempt::query()->distinct()->pluck('provider', 'provider')->toArray()),
                SelectFilter::make('currency_code')
                    ->label('Currency')
                    ->options(fn (): array => InstructorPayoutAttempt::query()->distinct()->pluck('currency_code', 'currency_code')->toArray()),
                SelectFilter::make('failure_category')
                    ->options(collect(PayoutFailureCategory::cases())
                        ->mapWithKeys(fn (PayoutFailureCategory $c) => [$c->value => $c->label()])
                        ->toArray()),
                TernaryFilter::make('reconciliation_required')
                    ->label('Reconciliation required')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('reconciliationIssues', fn ($q) => $q->open()),
                        false: fn (Builder $query) => $query->whereDoesntHave('reconciliationIssues', fn ($q) => $q->open()),
                    ),
                Filter::make('queued_between')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Cancels this attempt before the provider has accepted it. The withdrawal returns to approved.')
                    ->authorize(fn (InstructorPayoutAttempt $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->visible(fn (InstructorPayoutAttempt $record): bool => $record->status->isCancellable())
                    ->action(fn (InstructorPayoutAttempt $record) => self::callService(
                        fn () => app(InstructorPayoutExecutionServiceInterface::class)->cancelBeforeAcceptance($record, auth()->user()),
                        'Payout attempt cancelled',
                    )),

                Action::make('reconcile_now')
                    ->label('Reconcile Now')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Polls the provider for this attempt\'s current status and applies any confirmed outcome.')
                    ->authorize(fn (InstructorPayoutAttempt $record): bool => auth()->user()?->can('reconcile', $record) ?? false)
                    ->visible(fn (InstructorPayoutAttempt $record): bool => ! $record->status->isTerminal() && $record->status !== InstructorPayoutAttemptStatus::Succeeded)
                    ->action(fn (InstructorPayoutAttempt $record) => self::callService(
                        fn () => app(InstructorPayoutReconciliationServiceInterface::class)->reconcileAttempt($record),
                        'Reconciliation check complete',
                    )),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback();
            Notification::make()->title($successTitle)->success()->send();
        } catch (EarningException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
