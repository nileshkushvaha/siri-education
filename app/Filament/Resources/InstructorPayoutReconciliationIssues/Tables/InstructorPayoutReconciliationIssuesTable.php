<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutReconciliationIssues\Tables;

use App\Earnings\Contracts\InstructorPayoutReconciliationServiceInterface;
use App\Earnings\Enums\PayoutReconciliationIssueStatus;
use App\Earnings\Enums\PayoutReconciliationIssueType;
use App\Earnings\Enums\PayoutReconciliationSeverity;
use App\Earnings\Exceptions\EarningException;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\InstructorPayoutReconciliationIssue;
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
 * No status select, no editing, no delete. "Resolve" always requires a
 * mandatory evidence note and only ever closes this row — it can never
 * mark a withdrawal paid.
 */
class InstructorPayoutReconciliationIssuesTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn (PayoutReconciliationSeverity $state): string => $state->label())
                    ->color(fn (PayoutReconciliationSeverity $state): string => $state->color()),
                TextColumn::make('type')
                    ->formatStateUsing(fn (PayoutReconciliationIssueType $state): string => $state->label()),
                TextColumn::make('withdrawalRequest.reference')
                    ->label('Withdrawal')
                    ->searchable(),
                TextColumn::make('local_status')
                    ->label('Local Status')
                    ->placeholder('—'),
                TextColumn::make('provider_status')
                    ->label('Provider Status')
                    ->placeholder('—'),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->state(fn (InstructorPayoutReconciliationIssue $record): ?string => $record->amount_minor !== null && $record->currency_code !== null
                        ? MoneyFormatter::format($record->amount_minor, $record->currency_code)
                        : null)
                    ->placeholder('—'),
                TextColumn::make('safe_summary')
                    ->label('Summary')
                    ->limit(60),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PayoutReconciliationIssueStatus $state): string => $state->label())
                    ->color(fn (PayoutReconciliationIssueStatus $state): string => $state->color()),
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
                    ->options(collect(PayoutReconciliationIssueStatus::cases())
                        ->mapWithKeys(fn (PayoutReconciliationIssueStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('severity')
                    ->options(collect(PayoutReconciliationSeverity::cases())
                        ->mapWithKeys(fn (PayoutReconciliationSeverity $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('type')
                    ->options(collect(PayoutReconciliationIssueType::cases())
                        ->mapWithKeys(fn (PayoutReconciliationIssueType $t) => [$t->value => $t->label()])
                        ->toArray()),
            ])
            ->recordActions([
                Action::make('assign')
                    ->label('Assign')
                    ->icon('heroicon-o-user-plus')
                    ->authorize(fn (InstructorPayoutReconciliationIssue $record): bool => auth()->user()?->can('assign', $record) ?? false)
                    ->visible(fn (InstructorPayoutReconciliationIssue $record): bool => $record->status->isOpen())
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
                    ->action(fn (InstructorPayoutReconciliationIssue $record, array $data) => self::callService(
                        fn () => app(InstructorPayoutReconciliationServiceInterface::class)->assign($record, User::findOrFail($data['assignee_id']), auth()->user()),
                        'Issue assigned',
                    )),

                Action::make('investigate')
                    ->label('Investigate')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('warning')
                    ->authorize(fn (InstructorPayoutReconciliationIssue $record): bool => auth()->user()?->can('assign', $record) ?? false)
                    ->visible(fn (InstructorPayoutReconciliationIssue $record): bool => $record->status === PayoutReconciliationIssueStatus::Open)
                    ->action(fn (InstructorPayoutReconciliationIssue $record) => self::callService(
                        fn () => app(InstructorPayoutReconciliationServiceInterface::class)->startInvestigating($record, auth()->user()),
                        'Marked as investigating',
                    )),

                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Closes this reconciliation issue. This never marks a withdrawal paid — only a provider-confirmed outcome can do that.')
                    ->authorize(fn (InstructorPayoutReconciliationIssue $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->visible(fn (InstructorPayoutReconciliationIssue $record): bool => $record->status->isOpen())
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
                    ->action(fn (InstructorPayoutReconciliationIssue $record, array $data) => self::callService(
                        fn () => app(InstructorPayoutReconciliationServiceInterface::class)->resolve($record, auth()->user(), $data['resolution_type'], $data['note']),
                        'Issue resolved',
                    )),
            ])
            ->defaultSort('last_detected_at', 'desc');

        return AdminListTable::apply($table);
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
