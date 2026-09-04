<?php

namespace App\Filament\Resources\PageBlocks\Tables;

use App\Enums\BlockType;
use App\Filament\Support\Tables\AdminListTable;
use App\Services\BlockService;
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
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PageBlocksTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->placeholder('—')
                    ->searchable()
                    ->description(fn ($record): string => $record->block_type instanceof BlockType
                        ? $record->block_type->label()
                        : (string) $record->block_type
                    )
                    ->weight('semibold'),

                TextColumn::make('blockable_type')
                    ->label('Owner')
                    ->formatStateUsing(fn ($state, $record): string => match ($state) {
                        'App\Models\Post' => '📝 Post: '.($record->post?->title ?? '—'),
                        default => '📄 Page: '.($record->page?->title ?? '—'),
                    })
                    ->searchable(false),

                TextColumn::make('block_type')
                    ->label('Block Type')
                    ->badge()
                    ->color(fn (BlockType $state): string => $state->color())
                    ->formatStateUsing(fn (BlockType $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable()
                    ->width('100px'),

                TextColumn::make('position')
                    ->label('Position')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'before_content' ? 'info' : 'gray')
                    ->formatStateUsing(fn (string $state): string => $state === 'before_content' ? 'Before' : 'After')
                    ->sortable(),

                ToggleColumn::make('is_active')
                    ->label('Active'),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('blockable_type')
                    ->label('Owner Type')
                    ->options([
                        'App\Models\Page' => 'Pages',
                        'App\Models\Post' => 'Posts',
                    ]),

                SelectFilter::make('block_type')
                    ->label('Block Type')
                    ->options(BlockType::class)
                    ->multiple(),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function ($record, $action) {
                        try {
                            app(BlockService::class)->duplicateBlock($record);
                            Notification::make()
                                ->title('Block duplicated successfully')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error duplicating block')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
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
            ]);

        return AdminListTable::apply($table);
    }
}
