<?php

declare(strict_types=1);

namespace App\Filament\Resources\SuspiciousActivityFlags\Tables;

use App\Compliance\Enums\SuspiciousActivityCategory;
use App\Compliance\Enums\SuspiciousActivityFlagDecision;
use App\Compliance\Enums\SuspiciousActivityFlagSeverity;
use App\Compliance\Enums\SuspiciousActivityFlagStatus;
use App\Compliance\Enums\SuspiciousActivityRuleCode;
use App\Compliance\Exceptions\ComplianceValidationException;
use App\Compliance\Exceptions\InvalidSuspiciousActivityFlagTransitionException;
use App\Compliance\Services\ComplianceMonitoringService;
use App\Filament\Support\AdminDayRange;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\SuspiciousActivityFlag;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * No status select, no editing, no delete, no create. Every action
 * calls ComplianceMonitoringService exclusively — a flag is evidence
 * for human review, never proof of fraud, so no action here ever
 * suspends, blocks, freezes, or reverses anything.
 */
class SuspiciousActivityFlagsTable
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
                TextColumn::make('rule_code')
                    ->label('Rule')
                    ->formatStateUsing(fn (SuspiciousActivityRuleCode $state): string => $state->label()),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (SuspiciousActivityCategory $state): string => $state->label()),
                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn (SuspiciousActivityFlagSeverity $state): string => $state->label())
                    ->color(fn (SuspiciousActivityFlagSeverity $state): string => $state->color()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SuspiciousActivityFlagStatus $state): string => $state->label())
                    ->color(fn (SuspiciousActivityFlagStatus $state): string => $state->color()),
                TextColumn::make('subject.name')
                    ->label('Subject')
                    ->searchable(),
                TextColumn::make('occurrence_count')
                    ->label('Occurrences')
                    ->sortable(),
                TextColumn::make('first_observed_at')
                    ->label('First Observed')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_observed_at')
                    ->label('Last Observed')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('reviewer.name')
                    ->label('Reviewer')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SuspiciousActivityFlagStatus::cases())
                        ->mapWithKeys(fn (SuspiciousActivityFlagStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('severity')
                    ->options(collect(SuspiciousActivityFlagSeverity::cases())
                        ->mapWithKeys(fn (SuspiciousActivityFlagSeverity $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('rule_code')
                    ->label('Rule')
                    ->options(collect(SuspiciousActivityRuleCode::cases())
                        ->mapWithKeys(fn (SuspiciousActivityRuleCode $r) => [$r->value => $r->label()])
                        ->toArray()),
                SelectFilter::make('category')
                    ->options(collect(SuspiciousActivityCategory::cases())
                        ->mapWithKeys(fn (SuspiciousActivityCategory $c) => [$c->value => $c->label()])
                        ->toArray()),
                Filter::make('first_observed_between')
                    ->form([
                        DatePicker::make('observed_from'),
                        DatePicker::make('observed_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['observed_from'] ?? null, fn (Builder $q, string $date): Builder => $q->where('first_observed_at', '>=', AdminDayRange::viewerDay($date)->startUtc))
                            ->when($data['observed_until'] ?? null, fn (Builder $q, string $date): Builder => $q->where('first_observed_at', '<', AdminDayRange::viewerDay($date)->endUtcExclusive));
                    }),
            ])
            ->recordActions([
                Action::make('begin_review')
                    ->label('Begin review')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('warning')
                    ->authorize(fn (SuspiciousActivityFlag $record): bool => auth()->user()?->can('beginReview', $record) ?? false)
                    ->visible(fn (SuspiciousActivityFlag $record): bool => $record->status === SuspiciousActivityFlagStatus::Open)
                    ->action(fn (SuspiciousActivityFlag $record) => self::callService(
                        fn () => app(ComplianceMonitoringService::class)->startReview($record, auth()->user()),
                        'Marked as in review',
                        'Could not begin review',
                    )),

                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Records a review decision. A flag is evidence for human review, not proof of fraud — this never suspends an account, blocks a payment, or reverses anything.')
                    ->authorize(fn (SuspiciousActivityFlag $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->visible(fn (SuspiciousActivityFlag $record): bool => $record->status->isActive())
                    ->form([
                        Select::make('decision')
                            ->label('Decision')
                            ->options(collect(SuspiciousActivityFlagDecision::cases())
                                ->mapWithKeys(fn (SuspiciousActivityFlagDecision $d) => [$d->value => $d->label()])
                                ->toArray())
                            ->required(),
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(500)
                            ->helperText('Mandatory — record what review evidence supports this decision.'),
                    ])
                    ->action(fn (SuspiciousActivityFlag $record, array $data) => self::callService(
                        fn () => app(ComplianceMonitoringService::class)->resolve($record, auth()->user(), $data['reason'], SuspiciousActivityFlagDecision::from($data['decision'])),
                        'Flag resolved',
                        'Could not resolve flag',
                    )),

                Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Closes this flag without a confirmed-risk finding.')
                    ->authorize(fn (SuspiciousActivityFlag $record): bool => auth()->user()?->can('dismiss', $record) ?? false)
                    ->visible(fn (SuspiciousActivityFlag $record): bool => $record->status->isActive())
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(500)
                            ->helperText('Mandatory — record why this flag does not warrant further action.'),
                    ])
                    ->action(fn (SuspiciousActivityFlag $record, array $data) => self::callService(
                        fn () => app(ComplianceMonitoringService::class)->dismiss($record, auth()->user(), $data['reason']),
                        'Flag dismissed',
                        'Could not dismiss flag',
                    )),
            ])
            ->defaultSort('last_observed_at', 'desc');

        return AdminListTable::apply($table);
    }

    private static function callService(callable $callback, string $successTitle, string $failureTitle): void
    {
        try {
            $callback();
            Notification::make()->title($successTitle)->success()->send();
        } catch (AuthorizationException|ComplianceValidationException|InvalidSuspiciousActivityFlagTransitionException $e) {
            Notification::make()->title($failureTitle)->body($e->getMessage())->danger()->send();
        }
    }
}
