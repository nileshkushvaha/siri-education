<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Quality;

use App\Filament\Resources\Users\UserResource;
use App\Models\InstructorQualityAlert;
use App\Models\User;
use App\Quality\Contracts\InstructorQualityAlertServiceInterface;
use App\Quality\Enums\InstructorQualityAlertResolutionAction;
use App\Quality\Enums\InstructorQualityAlertSeverity;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Quality\Enums\InstructorQualityAlertType;
use App\Quality\Exceptions\InvalidInstructorQualityAlertTransitionException;
use App\Quality\Exceptions\QualityAlertValidationException;
use App\Quality\Support\QualityDashboardAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only instructor quality-alert queue. Every action delegates to
 * InstructorQualityAlertServiceInterface — this widget never writes
 * `quality_alerts.status`/`resolution_*` itself, and authorization on
 * every action is the exact same InstructorQualityAlertPolicy the
 * service already enforces (an instructor still cannot resolve an
 * alert about themself, even from here).
 */
class AlertQueueWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return QualityDashboardAccess::userCan('ViewInstructorQualityAlerts');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Instructor Quality Alerts')
            ->query(
                InstructorQualityAlert::query()
                    ->with(['instructor:id,name', 'assignee:id,name'])
                    ->orderByDesc('triggered_at'),
            )
            ->columns([
                TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->url(fn (InstructorQualityAlert $record) => UserResource::getUrl('view', ['record' => $record->instructor_id])),
                TextColumn::make('alert_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (InstructorQualityAlertType $state): string => $state->label())
                    ->color(fn (InstructorQualityAlertType $state): string => $state->color()),
                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn (InstructorQualityAlertSeverity $state): string => $state->label())
                    ->color(fn (InstructorQualityAlertSeverity $state): string => $state->color()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InstructorQualityAlertStatus $state): string => $state->label())
                    ->color(fn (InstructorQualityAlertStatus $state): string => $state->color()),
                TextColumn::make('signal_count')
                    ->label('Signals')
                    ->placeholder('—'),
                IconColumn::make('needs_reevaluation')
                    ->label('Stale')
                    ->boolean(),
                TextColumn::make('assignee.name')
                    ->label('Assigned To')
                    ->placeholder('Unassigned'),
                TextColumn::make('resolution_action')
                    ->label('Recommendation')
                    ->formatStateUsing(fn (?InstructorQualityAlertResolutionAction $state): string => $state?->label() ?? '—'),
                TextColumn::make('triggered_at')
                    ->label('Triggered')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('alert_type')
                    ->label('Type')
                    ->options(collect(InstructorQualityAlertType::cases())->mapWithKeys(fn (InstructorQualityAlertType $t) => [$t->value => $t->label()])),
                SelectFilter::make('severity')
                    ->options(collect(InstructorQualityAlertSeverity::cases())->mapWithKeys(fn (InstructorQualityAlertSeverity $s) => [$s->value => $s->label()])),
                SelectFilter::make('status')
                    ->options(collect(InstructorQualityAlertStatus::cases())->mapWithKeys(fn (InstructorQualityAlertStatus $s) => [$s->value => $s->label()])),
                SelectFilter::make('assigned_to')
                    ->label('Assigned To')
                    ->relationship('assignee', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('instructor_id')
                    ->label('Instructor')
                    ->relationship('instructor', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('triggered_between')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From')->native(false),
                        DatePicker::make('until')->label('Until')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('triggered_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('triggered_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('start_review')
                    ->label('Start Review')
                    ->icon('heroicon-m-eye')
                    ->authorize(fn (InstructorQualityAlert $record): bool => Auth::user()?->can('resolve', $record) ?? false)
                    ->visible(fn (InstructorQualityAlert $record): bool => $record->status === InstructorQualityAlertStatus::Open)
                    ->action(fn (InstructorQualityAlert $record) => self::callService(
                        fn (InstructorQualityAlertServiceInterface $service) => $service->startReview($record, Auth::user()),
                        'Review started',
                        'Could not start review',
                    )),

                Action::make('assign')
                    ->icon('heroicon-m-user-plus')
                    ->authorize(fn (InstructorQualityAlert $record): bool => Auth::user()?->can('resolve', $record) ?? false)
                    ->form([
                        Select::make('assignee_id')
                            ->label('Assign To')
                            ->options(fn () => self::managerOptions())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(fn (InstructorQualityAlert $record, array $data) => self::callService(
                        fn (InstructorQualityAlertServiceInterface $service) => $service->assign(
                            $record,
                            Auth::user(),
                            User::query()->findOrFail($data['assignee_id']),
                        ),
                        'Alert assigned',
                        'Could not assign alert',
                    )),

                Action::make('resolve')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->authorize(fn (InstructorQualityAlert $record): bool => Auth::user()?->can('resolve', $record) ?? false)
                    ->visible(fn (InstructorQualityAlert $record): bool => $record->status->isActive())
                    ->form([
                        Textarea::make('reason')->label('Resolution Reason')->required()->maxLength(500),
                        Select::make('action')
                            ->label('Recommendation')
                            ->options(collect(InstructorQualityAlertResolutionAction::cases())->mapWithKeys(fn (InstructorQualityAlertResolutionAction $a) => [$a->value => $a->label()]))
                            ->default(InstructorQualityAlertResolutionAction::NoAction->value)
                            ->required(),
                    ])
                    ->action(fn (InstructorQualityAlert $record, array $data) => self::callService(
                        fn (InstructorQualityAlertServiceInterface $service) => $service->resolve(
                            $record,
                            Auth::user(),
                            $data['reason'],
                            InstructorQualityAlertResolutionAction::from($data['action']),
                        ),
                        'Alert resolved',
                        'Could not resolve alert',
                    )),

                Action::make('dismiss')
                    ->icon('heroicon-m-x-circle')
                    ->color('gray')
                    ->authorize(fn (InstructorQualityAlert $record): bool => Auth::user()?->can('resolve', $record) ?? false)
                    ->visible(fn (InstructorQualityAlert $record): bool => $record->status->isActive())
                    ->form([
                        Textarea::make('reason')->label('Dismissal Reason')->required()->maxLength(500),
                    ])
                    ->action(fn (InstructorQualityAlert $record, array $data) => self::callService(
                        fn (InstructorQualityAlertServiceInterface $service) => $service->dismiss($record, Auth::user(), $data['reason']),
                        'Alert dismissed',
                        'Could not dismiss alert',
                    )),

                Action::make('mark_duplicate')
                    ->label('Mark Duplicate')
                    ->icon('heroicon-m-document-duplicate')
                    ->color('gray')
                    ->authorize(fn (InstructorQualityAlert $record): bool => Auth::user()?->can('resolve', $record) ?? false)
                    ->visible(fn (InstructorQualityAlert $record): bool => $record->status->isActive())
                    ->form([
                        Textarea::make('reason')->label('Reason (optional)')->maxLength(500),
                    ])
                    ->action(fn (InstructorQualityAlert $record, array $data) => self::callService(
                        fn (InstructorQualityAlertServiceInterface $service) => $service->markDuplicate($record, Auth::user(), $data['reason'] ?? null),
                        'Alert marked duplicate',
                        'Could not mark alert as duplicate',
                    )),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    /**
     * Extracted from the inline Select options closure so it's
     * directly testable. A temporarily-missing 'manager' role must yield
     * an empty option list, never a 500 on this widget's assign action.
     *
     * @return array<int, string>
     */
    private static function managerOptions(): array
    {
        return User::query()->whereHasRoleNamed('manager')->pluck('name', 'id')->all();
    }

    /** Run a service call, converting domain failures into panel notifications — mirrors BookingsTable::callService(). */
    private static function callService(callable $callback, string $successTitle, string $failureTitle): void
    {
        try {
            $callback(app(InstructorQualityAlertServiceInterface::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (AuthorizationException|QualityAlertValidationException|InvalidInstructorQualityAlertTransitionException $e) {
            Notification::make()->title($failureTitle)->body($e->getMessage())->danger()->send();
        }
    }
}
