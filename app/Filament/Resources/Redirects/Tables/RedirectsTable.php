<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Tables;

use App\Content\Redirects\Enums\RedirectType;
use App\Content\Redirects\Exceptions\RedirectException;
use App\Content\Redirects\Services\RedirectService;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\Redirect;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * No delete action — redirects are deactivated, never removed, so
 * historical SEO evidence (what used to redirect where) always
 * survives (requirement #5). Every mutation flows through
 * RedirectService; this table only wires actions to it.
 */
class RedirectsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('source_path')
                    ->label('Source')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('target_path')
                    ->label('Target')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (RedirectType $state): string => $state->label()),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->disabled(fn ($record): bool => ! (auth()->user()?->can('update', $record) ?? false)),
                TextColumn::make('description')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                SelectFilter::make('type')
                    ->options(collect(RedirectType::cases())->mapWithKeys(fn (RedirectType $t) => [$t->value => $t->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),

                Action::make('activate')
                    ->icon('heroicon-m-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (Redirect $record): bool => auth()->user()?->can('activate', $record) ?? false)
                    ->visible(fn (Redirect $record): bool => ! $record->is_active)
                    ->action(fn (Redirect $record) => self::callService(
                        fn (RedirectService $service) => $service->activate(auth()->user(), $record),
                        'Redirect activated',
                    )),

                Action::make('deactivate')
                    ->icon('heroicon-m-pause')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->authorize(fn (Redirect $record): bool => auth()->user()?->can('deactivate', $record) ?? false)
                    ->visible(fn (Redirect $record): bool => $record->is_active)
                    ->action(fn (Redirect $record) => self::callService(
                        fn (RedirectService $service) => $service->deactivate(auth()->user(), $record),
                        'Redirect deactivated',
                    )),
            ])
            ->defaultSort('updated_at', 'desc');

        return AdminListTable::apply($table);
    }

    private static function callService(callable $callback, string $successTitle): void
    {
        try {
            $callback(app(RedirectService::class));
            Notification::make()->title($successTitle)->success()->send();
        } catch (RedirectException $e) {
            Notification::make()->title('Action failed')->body($e->getMessage())->danger()->send();
        }
    }
}
