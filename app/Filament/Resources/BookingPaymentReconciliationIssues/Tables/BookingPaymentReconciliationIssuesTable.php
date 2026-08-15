<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingPaymentReconciliationIssues\Tables;

use App\Booking\Contracts\BookingPaymentReconciliationServiceInterface;
use App\Booking\Enums\BookingPaymentReconciliationIssueStatus;
use App\Booking\Enums\BookingPaymentReconciliationIssueType;
use App\Booking\Enums\BookingPaymentReconciliationSeverity;
use App\Booking\Exceptions\BookingException;
use App\Models\BookingPaymentReconciliationIssue;
use App\Models\User;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * No status select, no editing, no delete, no manual mark-paid action.
 * "Resolve" always requires a mandatory evidence note and only ever
 * closes this row — it can never mark a booking paid. "Reconcile now"
 * re-runs an authenticated provider status fetch through the exact same
 * BookingPaymentService::applyProviderStatus() path the scheduled sweep
 * and webhook use — never a separate settlement code path.
 */
class BookingPaymentReconciliationIssuesTable
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
                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn (BookingPaymentReconciliationSeverity $state): string => $state->label())
                    ->color(fn (BookingPaymentReconciliationSeverity $state): string => $state->color()),
                TextColumn::make('type')
                    ->formatStateUsing(fn (BookingPaymentReconciliationIssueType $state): string => $state->label()),
                TextColumn::make('bookingPayment.booking.reference')
                    ->label('Booking')
                    ->searchable(),
                TextColumn::make('local_status')
                    ->label('Local Status')
                    ->placeholder('—'),
                TextColumn::make('provider_status')
                    ->label('Provider Status')
                    ->placeholder('—'),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->state(fn (BookingPaymentReconciliationIssue $record): ?string => $record->amount_minor !== null && $record->currency_code !== null
                        ? MoneyFormatter::format($record->amount_minor, $record->currency_code)
                        : null)
                    ->placeholder('—'),
                TextColumn::make('safe_summary')
                    ->label('Summary')
                    ->limit(60),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BookingPaymentReconciliationIssueStatus $state): string => $state->label())
                    ->color(fn (BookingPaymentReconciliationIssueStatus $state): string => $state->color()),
                TextColumn::make('assignee.name')
                    ->label('Assigned To')
                    ->placeholder('Unassigned')
                    ->toggleable(),
                TextColumn::make('first_detected_at')
                    ->label('First Detected')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_detected_at')
                    ->label('Last Detected')
                    ->dateTime()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(BookingPaymentReconciliationIssueStatus::cases())
                        ->mapWithKeys(fn (BookingPaymentReconciliationIssueStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('severity')
                    ->options(collect(BookingPaymentReconciliationSeverity::cases())
                        ->mapWithKeys(fn (BookingPaymentReconciliationSeverity $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('type')
                    // Only types the platform can actually generate. The
                    // filter previously offered all twelve while only two
                    // had a producer, so an operator could search for a
                    // state that cannot exist and read the empty result as
                    // reassurance. Dormant cases remain hydratable for any
                    // historical row — they are simply not offered as
                    // something to look for.
                    ->options(collect(BookingPaymentReconciliationIssueType::live())
                        ->mapWithKeys(fn (BookingPaymentReconciliationIssueType $t) => [$t->value => $t->label()])
                        ->toArray()),
            ])
            ->recordActions([
                Action::make('reconcile_now')
                    ->label('Reconcile now')
                    ->icon('heroicon-o-arrow-path')
                    ->authorize(fn (BookingPaymentReconciliationIssue $record): bool => auth()->user()?->can('reconcileNow', $record) ?? false)
                    ->visible(fn (BookingPaymentReconciliationIssue $record): bool => $record->status->isOpen())
                    ->action(fn (BookingPaymentReconciliationIssue $record) => self::callService(
                        fn () => app(BookingPaymentReconciliationServiceInterface::class)->reconcileAttempt($record->bookingPayment),
                        'Reconciliation re-run',
                    )),

                Action::make('assign')
                    ->label('Assign')
                    ->icon('heroicon-o-user-plus')
                    ->authorize(fn (BookingPaymentReconciliationIssue $record): bool => auth()->user()?->can('assign', $record) ?? false)
                    ->visible(fn (BookingPaymentReconciliationIssue $record): bool => $record->status->isOpen())
                    ->form([
                        Select::make('assignee_id')
                            ->label('Assign to')
                            ->options(fn (): array => User::query()
                                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['manager', 'super_admin']))
                                ->pluck('name', 'id')
                                ->toArray())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(fn (BookingPaymentReconciliationIssue $record, array $data) => self::callService(
                        fn () => app(BookingPaymentReconciliationServiceInterface::class)->assign($record, User::findOrFail($data['assignee_id']), auth()->user()),
                        'Issue assigned',
                    )),

                Action::make('investigate')
                    ->label('Investigate')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('warning')
                    ->authorize(fn (BookingPaymentReconciliationIssue $record): bool => auth()->user()?->can('assign', $record) ?? false)
                    ->visible(fn (BookingPaymentReconciliationIssue $record): bool => $record->status === BookingPaymentReconciliationIssueStatus::Open)
                    ->action(fn (BookingPaymentReconciliationIssue $record) => self::callService(
                        fn () => app(BookingPaymentReconciliationServiceInterface::class)->startInvestigating($record, auth()->user()),
                        'Marked as investigating',
                    )),

                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Closes this reconciliation issue. This never marks a booking paid — only a provider-confirmed outcome can do that.')
                    ->authorize(fn (BookingPaymentReconciliationIssue $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->visible(fn (BookingPaymentReconciliationIssue $record): bool => $record->status->isOpen())
                    ->form([
                        Select::make('resolution_type')
                            ->label('Resolution type')
                            ->options([
                                'confirmed_success' => 'Confirmed success (provider evidence)',
                                'confirmed_failure' => 'Confirmed failure (provider evidence)',
                                'operational_fixed' => 'Operational issue fixed',
                                'false_positive' => 'False positive',
                                'other' => 'Other (see note)',
                            ])
                            ->required(),
                        Textarea::make('note')
                            ->label('Evidence / resolution note')
                            ->required()
                            ->maxLength(1000)
                            ->helperText('Mandatory — record what provider evidence or investigation resolved this.'),
                    ])
                    ->action(fn (BookingPaymentReconciliationIssue $record, array $data) => self::callService(
                        fn () => app(BookingPaymentReconciliationServiceInterface::class)->resolve($record, auth()->user(), $data['resolution_type'], $data['note']),
                        'Issue resolved',
                    )),
            ])
            ->defaultSort('last_detected_at', 'desc');
    }

    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback();
            Notification::make()->title($successTitle)->success()->send();
        } catch (BookingException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
