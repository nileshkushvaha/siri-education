<?php

namespace App\Filament\Resources\Faq\Tables;

use App\Enums\FaqAudience;
use App\Enums\FaqStatus;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\Faq;
use App\Services\Faq\FaqService;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class FaqsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                TextColumn::make('question')
                    ->searchable()
                    ->limit(80)
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (FaqStatus $state): string => $state->color()),
                TextColumn::make('audience')
                    ->label('Audience')
                    ->formatStateUsing(fn ($state): string => implode(', ', array_map(
                        fn (string $v) => FaqAudience::from($v)->label(),
                        (array) $state
                    )))
                    ->wrap(),
                ToggleColumn::make('featured')
                    ->label('Featured')
                    ->disabled(fn ($record): bool => ! (auth()->user()?->can('update', $record) ?? false)),
                TextColumn::make('display_order')
                    ->label('Order')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(
                        collect(FaqStatus::cases())
                            ->mapWithKeys(fn (FaqStatus $s) => [$s->value => $s->label()])
                            ->toArray()
                    ),
                SelectFilter::make('faq_category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('featured')
                    ->label('Featured'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-m-arrow-up-circle')
                    ->authorize(fn (Faq $record) => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (Faq $record) => $record->status !== FaqStatus::Published)
                    ->action(function (Faq $record): void {
                        $record->update(['status' => FaqStatus::Published->value]);
                        app(FaqService::class)->clearCache();
                        Notification::make()->title('FAQ published successfully')->success()->send();
                    }),
                Action::make('unpublish')
                    ->label('Unpublish')
                    ->icon('heroicon-m-arrow-down-circle')
                    ->authorize(fn (Faq $record) => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (Faq $record) => $record->status === FaqStatus::Published)
                    ->action(function (Faq $record): void {
                        $record->update(['status' => FaqStatus::Draft->value]);
                        app(FaqService::class)->clearCache();
                        Notification::make()->title('FAQ unpublished')->warning()->send();
                    }),
                DeleteAction::make()
                    ->after(fn () => app(FaqService::class)->clearCache()),
                RestoreAction::make()
                    ->after(fn () => app(FaqService::class)->clearCache()),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->after(fn () => app(FaqService::class)->clearCache()),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make()
                        ->after(fn () => app(FaqService::class)->clearCache()),
                ]),
            ])
            ->searchable(['question', 'answer', 'category.name'])
            ->defaultSort('display_order');

        return AdminListTable::apply($table);
    }
}
