<?php

declare(strict_types=1);

namespace App\Filament\Resources\Navigation\Tables;

use App\Enums\Navigation\NavigationLayoutType;
use App\Enums\Navigation\NavigationLocation;
use App\Enums\Navigation\NavigationStatus;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\NavigationMenu;
use App\Navigation\Contracts\NavigationCacheInterface;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class NavigationMenusTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable()
                    ->color('gray'),
                TextColumn::make('location')
                    ->badge()
                    ->color(fn (NavigationLocation $state): string => $state->color())
                    ->formatStateUsing(fn (NavigationLocation $state): string => $state->label()),
                TextColumn::make('layout_type')
                    ->label('Layout')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state): string => $state instanceof NavigationLayoutType ? $state->label() : $state),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (NavigationStatus $state): string => $state->color())
                    ->formatStateUsing(fn (NavigationStatus $state): string => $state->label()),
                TextColumn::make('locale')
                    ->placeholder('All')
                    ->toggleable(),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(NavigationStatus::cases())->mapWithKeys(
                        fn (NavigationStatus $case) => [$case->value => $case->label()],
                    ))
                    ->multiple(),
                SelectFilter::make('location')
                    ->options(collect(NavigationLocation::cases())->mapWithKeys(
                        fn (NavigationLocation $case) => [$case->value => $case->label()],
                    ))
                    ->multiple(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-m-arrow-up-circle')
                    ->color('success')
                    ->authorize(fn (NavigationMenu $record) => auth()->user()?->can('publish', $record) ?? false)
                    ->visible(fn (NavigationMenu $record) => $record->status !== NavigationStatus::Published)
                    ->action(function (NavigationMenu $record): void {
                        $record->update(['status' => NavigationStatus::Published]);
                        app(NavigationCacheInterface::class)->invalidateForMenu($record->id);
                        Notification::make()->title('Navigation menu published')->success()->send();
                    }),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-m-archive-box')
                    ->color('warning')
                    ->authorize(fn (NavigationMenu $record) => auth()->user()?->can('publish', $record) ?? false)
                    ->visible(fn (NavigationMenu $record) => $record->status !== NavigationStatus::Archived)
                    ->action(function (NavigationMenu $record): void {
                        $record->update(['status' => NavigationStatus::Archived]);
                        app(NavigationCacheInterface::class)->invalidateForMenu($record->id);
                        Notification::make()->title('Navigation menu archived')->warning()->send();
                    }),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-m-document-duplicate')
                    ->authorize(fn () => auth()->user()?->can('create', NavigationMenu::class) ?? false)
                    ->action(function (NavigationMenu $record): void {
                        $newMenu = $record->replicate(['id', 'created_at', 'updated_at']);
                        $newMenu->name = $record->name.' (Copy)';
                        $newMenu->slug = Str::slug($newMenu->name).'-'.Str::random(4);
                        $newMenu->status = NavigationStatus::Draft;
                        $newMenu->save();

                        Notification::make()
                            ->title('Navigation menu duplicated')
                            ->body("New menu: {$newMenu->name}")
                            ->success()
                            ->send();
                    }),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');

        return AdminListTable::apply($table);
    }
}
