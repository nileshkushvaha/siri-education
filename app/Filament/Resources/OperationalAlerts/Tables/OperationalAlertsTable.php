<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalAlerts\Tables;

use App\Alerts\Enums\OperationalAlertCategory;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertStatus;
use App\Alerts\Enums\OperationalAlertType;
use App\Alerts\Exceptions\OperationalAlertValidationException;
use App\Alerts\Services\OperationalAlertService;
use App\Filament\Support\AdminDayRange;
use App\Models\OperationalAlert;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
 * calls OperationalAlertService exclusively.
 */
class OperationalAlertsTable
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
                TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn (OperationalAlertType $state): string => $state->label()),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (OperationalAlertCategory $state): string => $state->label()),
                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn (OperationalAlertSeverity $state): string => $state->label())
                    ->color(fn (OperationalAlertSeverity $state): string => $state->color()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (OperationalAlertStatus $state): string => $state->label())
                    ->color(fn (OperationalAlertStatus $state): string => $state->color()),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(60),
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
                TextColumn::make('acknowledgedByUser.name')
                    ->label('Acknowledged By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(OperationalAlertStatus::cases())
                        ->mapWithKeys(fn (OperationalAlertStatus $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('severity')
                    ->options(collect(OperationalAlertSeverity::cases())
                        ->mapWithKeys(fn (OperationalAlertSeverity $s) => [$s->value => $s->label()])
                        ->toArray()),
                SelectFilter::make('category')
                    ->options(collect(OperationalAlertCategory::cases())
                        ->mapWithKeys(fn (OperationalAlertCategory $c) => [$c->value => $c->label()])
                        ->toArray()),
                SelectFilter::make('type')
                    ->options(collect(OperationalAlertType::cases())
                        ->mapWithKeys(fn (OperationalAlertType $t) => [$t->value => $t->label()])
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
                Action::make('acknowledge')
                    ->label('Acknowledge')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->authorize(fn (OperationalAlert $record): bool => auth()->user()?->can('acknowledge', $record) ?? false)
                    ->visible(fn (OperationalAlert $record): bool => $record->status === OperationalAlertStatus::Open)
                    ->action(fn (OperationalAlert $record) => self::callService(
                        fn () => app(OperationalAlertService::class)->acknowledge($record, auth()->user()),
                        'Alert acknowledged',
                    )),

                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Records the resolution. Historical alerts are never deleted.')
                    ->authorize(fn (OperationalAlert $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->visible(fn (OperationalAlert $record): bool => $record->status->isActive())
                    ->form([
                        Textarea::make('reason')
                            ->label('Resolution reason')
                            ->required()
                            ->maxLength(500)
                            ->helperText('Mandatory — record how this was resolved.'),
                    ])
                    ->action(fn (OperationalAlert $record, array $data) => self::callService(
                        fn () => app(OperationalAlertService::class)->resolve($record, auth()->user(), $data['reason']),
                        'Alert resolved',
                    )),

                Action::make('view_related_record')
                    ->label('View related record')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (OperationalAlert $record): ?string => $record->subjectUrl())
                    ->openUrlInNewTab()
                    ->visible(fn (OperationalAlert $record): bool => $record->subjectUrl() !== null),
            ])
            ->defaultSort('last_observed_at', 'desc');
    }

    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback();
            Notification::make()->title($successTitle)->success()->send();
        } catch (AuthorizationException|OperationalAlertValidationException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
