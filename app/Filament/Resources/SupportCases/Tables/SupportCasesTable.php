<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases\Tables;

use App\Models\SupportCase;
use App\Models\User;
use App\SupportCases\Enums\SupportCaseCategory;
use App\SupportCases\Enums\SupportCasePriority;
use App\SupportCases\Enums\SupportCaseResolutionType;
use App\SupportCases\Enums\SupportCaseStatus;
use App\SupportCases\Enums\SupportCaseType;
use App\SupportCases\Exceptions\InvalidSupportCaseTransitionException;
use App\SupportCases\Exceptions\UnauthorizedLinkedRecordException;
use App\SupportCases\Services\SupportCaseService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every lifecycle mutation routes through SupportCaseService — no
 * table action ever writes `support_cases`/`support_case_replies`
 * directly.
 */
class SupportCasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('case_number')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('type')
                    ->formatStateUsing(fn (SupportCaseType $state): string => $state->label())
                    ->toggleable(),
                TextColumn::make('category')
                    ->formatStateUsing(fn (SupportCaseCategory $state): string => $state->label()),
                TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(fn (SupportCasePriority $state): string => $state->label())
                    ->color(fn (SupportCasePriority $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SupportCaseStatus $state): string => $state->label())
                    ->color(fn (SupportCaseStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('assignee.name')
                    ->label('Assigned to')
                    ->placeholder('Unassigned')
                    ->toggleable(),
                TextColumn::make('subject')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('opened_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(SupportCaseType::cases())->mapWithKeys(fn (SupportCaseType $t) => [$t->value => $t->label()])),
                SelectFilter::make('category')
                    ->options(collect(SupportCaseCategory::cases())->mapWithKeys(fn (SupportCaseCategory $c) => [$c->value => $c->label()])),
                SelectFilter::make('priority')
                    ->options(collect(SupportCasePriority::cases())->mapWithKeys(fn (SupportCasePriority $p) => [$p->value => $p->label()])),
                SelectFilter::make('status')
                    ->options(collect(SupportCaseStatus::cases())->mapWithKeys(fn (SupportCaseStatus $s) => [$s->value => $s->label()])),
                SelectFilter::make('assigned_to')
                    ->label('Assigned To')
                    ->relationship('assignee', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('opened_between')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('opened_at', '>=', $date))
                        ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('opened_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('assign')
                    ->icon('heroicon-m-user-plus')
                    ->color('info')
                    ->authorize(fn (SupportCase $record): bool => auth()->user()?->can('assign', $record) ?? false)
                    ->form([
                        Select::make('assignee_id')
                            ->label('Assign to')
                            ->relationship('assignee', 'name', fn (Builder $query) => $query->whereHas('roles', fn ($q) => $q->whereIn('name', ['manager', 'super_admin'])))
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->action(fn (SupportCase $record, array $data) => self::callService(
                        fn (SupportCaseService $service) => $service->assign($record, auth()->user(), User::query()->findOrFail($data['assignee_id'])),
                        'Case assigned',
                    )),

                Action::make('escalate')
                    ->icon('heroicon-m-exclamation-triangle')
                    ->color('danger')
                    ->authorize(fn (SupportCase $record): bool => auth()->user()?->can('escalate', $record) ?? false)
                    ->visible(fn (SupportCase $record): bool => $record->status->canTransitionTo(SupportCaseStatus::Escalated))
                    ->form([
                        Textarea::make('reason')->required()->maxLength(500),
                    ])
                    ->action(fn (SupportCase $record, array $data) => self::callService(
                        fn (SupportCaseService $service) => $service->escalate($record, auth()->user(), $data['reason']),
                        'Case escalated',
                    )),

                Action::make('waiting_for_user')
                    ->label('Waiting for User')
                    ->icon('heroicon-m-clock')
                    ->color('warning')
                    ->authorize(fn (SupportCase $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->visible(fn (SupportCase $record): bool => $record->status->canTransitionTo(SupportCaseStatus::WaitingForUser))
                    ->action(fn (SupportCase $record) => self::callService(
                        fn (SupportCaseService $service) => $service->markWaitingForUser($record, auth()->user()),
                        'Case marked waiting for user',
                    )),

                Action::make('resolve')
                    ->icon('heroicon-m-check-badge')
                    ->color('success')
                    ->authorize(fn (SupportCase $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->visible(fn (SupportCase $record): bool => $record->status->canTransitionTo(SupportCaseStatus::Resolved))
                    ->form([
                        Select::make('resolution_type')
                            ->options(collect(SupportCaseResolutionType::cases())->mapWithKeys(fn (SupportCaseResolutionType $t) => [$t->value => $t->label()]))
                            ->required()
                            ->native(false),
                        Textarea::make('resolution_summary')->required()->maxLength(2000),
                    ])
                    ->action(fn (SupportCase $record, array $data) => self::callService(
                        fn (SupportCaseService $service) => $service->resolve($record, auth()->user(), SupportCaseResolutionType::from($data['resolution_type']), $data['resolution_summary']),
                        'Case resolved',
                    )),

                Action::make('close')
                    ->icon('heroicon-m-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->authorize(fn (SupportCase $record): bool => auth()->user()?->can('close', $record) ?? false)
                    ->visible(fn (SupportCase $record): bool => $record->status->canTransitionTo(SupportCaseStatus::Closed))
                    ->action(fn (SupportCase $record) => self::callService(
                        fn (SupportCaseService $service) => $service->close($record, auth()->user()),
                        'Case closed',
                    )),

                Action::make('reopen')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('warning')
                    ->authorize(fn (SupportCase $record): bool => auth()->user()?->can('reopen', $record) ?? false)
                    ->visible(fn (SupportCase $record): bool => $record->status->isReopenable())
                    ->form([
                        Textarea::make('reason')->maxLength(500),
                    ])
                    ->action(fn (SupportCase $record, array $data) => self::callService(
                        fn (SupportCaseService $service) => $service->reopen($record, auth()->user(), $data['reason'] ?? null),
                        'Case reopened',
                    )),
            ])
            ->defaultSort('opened_at', 'desc');
    }

    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(SupportCaseService::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (InvalidSupportCaseTransitionException|UnauthorizedLinkedRecordException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
