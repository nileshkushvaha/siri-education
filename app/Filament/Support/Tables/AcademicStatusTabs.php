<?php

declare(strict_types=1);

namespace App\Filament\Support\Tables;

use App\Enums\AcademicStatus;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ToggleColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * List-page tabs for every catalogue model that carries an AcademicStatus
 * column (subjects, topics, levels, education systems): All, Active,
 * Inactive, Archived, plus Deleted for soft-deletable models.
 *
 * @template TModel of Model
 */
final class AcademicStatusTabs
{
    /**
     * @param  class-string<TModel>  $model
     * @return array<string, Tab>
     */
    public static function make(string $model, bool $softDeletes = true): array
    {
        $count = fn (?AcademicStatus $status = null): int => $model::query()
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->count();

        $tabs = [
            'all' => Tab::make('All')->badge(fn (): int => $count()),
            'active' => Tab::make('Active')
                ->badge(fn (): int => $count(AcademicStatus::Active))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AcademicStatus::Active)),
            'inactive' => Tab::make('Inactive')
                ->badge(fn (): int => $count(AcademicStatus::Inactive))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AcademicStatus::Inactive)),
            'archived' => Tab::make('Archived')
                ->badge(fn (): int => $count(AcademicStatus::Archived))
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AcademicStatus::Archived)),
        ];

        if ($softDeletes) {
            $tabs['deleted'] = Tab::make('Deleted')
                ->badge(fn (): int => $model::query()->onlyTrashed()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->onlyTrashed());
        }

        return $tabs;
    }

    /**
     * Inline Active switch for AcademicStatus models: on = Active, off =
     * Inactive. Archived rows keep their state and the switch is locked;
     * so is anything the admin may not update.
     */
    public static function activeToggleColumn(): ToggleColumn
    {
        return ToggleColumn::make('is_active_switch')
            ->label('Active')
            ->getStateUsing(fn (Model $record): bool => $record->status === AcademicStatus::Active)
            ->updateStateUsing(function (Model $record, bool $state): void {
                $record->update(['status' => $state ? AcademicStatus::Active : AcademicStatus::Inactive]);
            })
            ->disabled(fn (Model $record): bool => $record->status === AcademicStatus::Archived
                || (method_exists($record, 'trashed') && $record->trashed())
                || ! (auth()->user()?->can('update', $record) ?? false))
            ->tooltip(fn (Model $record): ?string => $record->status === AcademicStatus::Archived ? 'Archived items cannot be switched on here. Edit the item to change its status.' : null)
            ->afterStateUpdated(fn () => Notification::make()->title('Status updated')->success()->send());
    }

    /**
     * Bulk activate / deactivate actions shared by the same models.
     *
     * @return array<int, BulkAction>
     */
    public static function bulkStatusActions(string $pluralLabel): array
    {
        return [
            BulkAction::make('activate')
                ->label('Activate')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->action(function (Collection $records) use ($pluralLabel): void {
                    $records->each->update(['status' => AcademicStatus::Active]);
                    Notification::make()->title("{$pluralLabel} activated")->success()->send();
                })
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('deactivate')
                ->label('Deactivate')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (Collection $records) use ($pluralLabel): void {
                    $records->each->update(['status' => AcademicStatus::Inactive]);
                    Notification::make()->title("{$pluralLabel} deactivated")->success()->send();
                })
                ->deselectRecordsAfterCompletion(),
        ];
    }
}
