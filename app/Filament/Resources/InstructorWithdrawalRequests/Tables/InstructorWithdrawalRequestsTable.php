<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorWithdrawalRequests\Tables;

use App\Earnings\Contracts\InstructorPayoutExecutionServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Exceptions\EarningException;
use App\Filament\Resources\InstructorPayoutAttempts\InstructorPayoutAttemptResource;
use App\Filament\Resources\InstructorPayoutReconciliationIssues\InstructorPayoutReconciliationIssueResource;
use App\Models\InstructorPayoutAttempt;
use App\Models\InstructorPayoutReconciliationIssue;
use App\Models\InstructorWithdrawalRequest;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lifecycle actions only — no status select, no editing of amount /
 * instructor / destination, no delete, no mark-paid. Every mutation
 * routes through InstructorWithdrawalService (which re-validates the
 * transition and, on approval, reservation integrity). The destination
 * column shows the masked snapshot label; the encrypted snapshot is
 * never rendered.
 */
class InstructorWithdrawalRequestsTable
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
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->state(fn (InstructorWithdrawalRequest $record): string => self::money($record->amount_minor, $record->currency_code))
                    ->sortable(),
                TextColumn::make('fee_minor')
                    ->label('Fee')
                    ->state(fn (InstructorWithdrawalRequest $record): string => self::money($record->fee_minor, $record->currency_code))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('net_amount_minor')
                    ->label('Net')
                    ->state(fn (InstructorWithdrawalRequest $record): string => self::money($record->net_amount_minor, $record->currency_code))
                    ->toggleable(),
                TextColumn::make('masked_identifier')
                    ->label('Destination'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InstructorWithdrawalStatus $state): string => $state->label())
                    ->color(fn (InstructorWithdrawalStatus $state): string => $state->color()),
                TextColumn::make('requested_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('review_started_at')
                    ->label('Review Started')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('reviewer.name')
                    ->label('Reviewer')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approved_at')
                    ->label('Approved')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('rejected_at')
                    ->label('Rejected')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rejection_reason')
                    ->label('Rejection Reason')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(InstructorWithdrawalStatus::cases())
                        ->mapWithKeys(fn (InstructorWithdrawalStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('instructor_id')
                    ->label('Instructor')
                    ->relationship('instructor', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('currency_code')
                    ->label('Currency')
                    ->options(fn (): array => InstructorWithdrawalRequest::query()
                        ->distinct()
                        ->pluck('currency_code', 'currency_code')
                        ->toArray()),
                TernaryFilter::make('reviewed')
                    ->label('Reviewed')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('review_started_at'),
                        false: fn (Builder $query) => $query->whereNull('review_started_at'),
                    ),
                Filter::make('requested_between')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('requested_at', '>=', $date))
                        ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('requested_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('start_review')
                    ->label('Start Review')
                    ->icon('heroicon-m-magnifying-glass')
                    ->color('info')
                    ->requiresConfirmation()
                    ->authorize(fn (InstructorWithdrawalRequest $record): bool => auth()->user()?->can('startReview', $record) ?? false)
                    ->visible(fn (InstructorWithdrawalRequest $record): bool => $record->status === InstructorWithdrawalStatus::Submitted)
                    ->action(fn (InstructorWithdrawalRequest $record) => self::callService(
                        fn (InstructorWithdrawalServiceInterface $service) => $service->startReview($record, auth()->user()),
                        'Review started',
                    )),

                Action::make('approve')
                    ->icon('heroicon-m-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Approves the request and keeps its earnings reserved for a future payout run. No money is moved.')
                    ->authorize(fn (InstructorWithdrawalRequest $record): bool => auth()->user()?->can('approve', $record) ?? false)
                    ->visible(fn (InstructorWithdrawalRequest $record): bool => $record->status->canTransitionTo(InstructorWithdrawalStatus::Approved))
                    ->action(fn (InstructorWithdrawalRequest $record) => self::callService(
                        fn (InstructorWithdrawalServiceInterface $service) => $service->approve($record, auth()->user()),
                        'Withdrawal approved — reservations retained',
                    )),

                Action::make('reject')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Rejects the request and releases its reserved earnings back to the pool.')
                    ->authorize(fn (InstructorWithdrawalRequest $record): bool => auth()->user()?->can('reject', $record) ?? false)
                    ->visible(fn (InstructorWithdrawalRequest $record): bool => $record->status->canTransitionTo(InstructorWithdrawalStatus::Rejected))
                    ->form([
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(1000)
                            ->helperText('Shown to the instructor — keep it actionable and free of internal detail.'),
                    ])
                    ->action(fn (InstructorWithdrawalRequest $record, array $data) => self::callService(
                        fn (InstructorWithdrawalServiceInterface $service) => $service->reject($record, auth()->user(), $data['reason']),
                        'Withdrawal rejected — reservations released',
                    )),

                Action::make('cancel')
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Cancels the request and releases its reserved earnings back to the pool.')
                    ->authorize(fn (InstructorWithdrawalRequest $record): bool => auth()->user()?->can('cancelAsAdmin', $record) ?? false)
                    ->visible(fn (InstructorWithdrawalRequest $record): bool => in_array($record->status, [InstructorWithdrawalStatus::Submitted, InstructorWithdrawalStatus::UnderReview], true))
                    ->form([
                        Textarea::make('reason')->maxLength(1000),
                    ])
                    ->action(fn (InstructorWithdrawalRequest $record, array $data) => self::callService(
                        fn (InstructorWithdrawalServiceInterface $service) => $service->cancelByAdmin($record, auth()->user(), $data['reason'] ?? null),
                        'Withdrawal cancelled — reservations released',
                    )),

                // ── Phase 16A execution segment ──────────────────────────
                Action::make('queue_for_execution')
                    ->label('Queue for Execution')
                    ->icon('heroicon-m-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (InstructorWithdrawalRequest $record): string => sprintf(
                        'Queues %s (%s) for payout execution against its immutable payout snapshot to %s. This dispatches a background job — no provider is called while this dialog is open.',
                        $record->reference,
                        self::money($record->amount_minor, $record->currency_code),
                        $record->masked_identifier,
                    ))
                    ->authorize(fn (InstructorWithdrawalRequest $record): bool => auth()->user()?->can('executePayout', $record) ?? false)
                    ->visible(fn (InstructorWithdrawalRequest $record): bool => $record->status->isExecutable())
                    ->action(fn (InstructorWithdrawalRequest $record) => self::callService(
                        fn () => app(InstructorPayoutExecutionServiceInterface::class)->queueExecution($record, auth()->user()),
                        'Withdrawal queued for payout execution',
                    )),

                Action::make('retry_payout')
                    ->label('Retry Payout')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Manually retries this withdrawal\'s payout execution, bypassing the automatic backoff schedule. A reason is required for the audit trail.')
                    ->authorize(fn (InstructorWithdrawalRequest $record): bool => auth()->user()?->can('retryPayout', $record) ?? false)
                    ->visible(fn (InstructorWithdrawalRequest $record): bool => in_array($record->status, [InstructorWithdrawalStatus::Failed, InstructorWithdrawalStatus::Processing], true))
                    ->form([
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->action(fn (InstructorWithdrawalRequest $record, array $data) => self::callService(
                        fn () => app(InstructorPayoutExecutionServiceInterface::class)->retry($record, auth()->user(), $data['reason']),
                        'Payout retry requested',
                    )),

                Action::make('view_payout_attempts')
                    ->label('View Payout Attempts')
                    ->icon('heroicon-m-list-bullet')
                    ->color('gray')
                    ->authorize(fn (InstructorWithdrawalRequest $record): bool => auth()->user()?->can('viewAny', InstructorPayoutAttempt::class) ?? false)
                    ->visible(fn (InstructorWithdrawalRequest $record): bool => $record->payoutAttempts()->exists())
                    ->url(fn (InstructorWithdrawalRequest $record): string => InstructorPayoutAttemptResource::getUrl('index', ['tableSearch' => $record->reference])),

                Action::make('view_reconciliation_issues')
                    ->label('View Reconciliation Issues')
                    ->icon('heroicon-m-shield-exclamation')
                    ->color('danger')
                    ->authorize(fn (InstructorWithdrawalRequest $record): bool => auth()->user()?->can('viewAny', InstructorPayoutReconciliationIssue::class) ?? false)
                    ->visible(fn (InstructorWithdrawalRequest $record): bool => $record->reconciliationIssues()->open()->exists())
                    ->url(fn (InstructorWithdrawalRequest $record): string => InstructorPayoutReconciliationIssueResource::getUrl('index')),
            ])
            ->defaultSort('requested_at', 'desc');
    }

    private static function money(int $minor, string $currencyCode): string
    {
        return MoneyFormatter::format($minor, $currencyCode);
    }

    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(InstructorWithdrawalServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (EarningException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
