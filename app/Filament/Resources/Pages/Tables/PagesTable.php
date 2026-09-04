<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\Page;
use App\Services\PageService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                ImageColumn::make('featured_image_url')
                    ->label('Featured Image')
                    ->circular()
                    ->default('/images/placeholder.png'),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->copyable()
                    ->sortable(),
                TextColumn::make('template')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PageStatus $state): string => match ($state) {
                        PageStatus::Draft => 'gray',
                        PageStatus::Published => 'success',
                        PageStatus::Scheduled => 'warning',
                        PageStatus::Archived => 'danger',
                    }),
                TextColumn::make('visibility')
                    ->badge()
                    ->color(fn (PageVisibility $state): string => match ($state) {
                        PageVisibility::Public => 'success',
                        PageVisibility::Private => 'warning',
                    }),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PageStatus::class)
                    ->multiple(),
                SelectFilter::make('template')
                    ->options([
                        'default' => 'Default',
                        'landing' => 'Landing Page',
                        'blank' => 'Blank',
                    ])
                    ->multiple(),
                SelectFilter::make('visibility')
                    ->options(PageVisibility::class)
                    ->multiple(),
                Filter::make('published')
                    ->query(fn (Builder $query) => $query->where('status', PageStatus::Published)),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-m-eye')
                    ->authorize(fn ($record) => auth()->user()?->can('view', $record) ?? false)
                    ->url(fn ($record) => route('admin.pages.preview', $record))
                    ->openUrlInNewTab(),
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-m-arrow-up-circle')
                    ->authorize(fn ($record) => auth()->user()?->can('publish', $record) ?? false)
                    ->visible(fn ($record) => $record->status !== PageStatus::Published)
                    ->action(function ($record) {
                        app(PageService::class)->publishPage($record);
                        Notification::make()
                            ->title('Page published successfully')
                            ->success()
                            ->send();
                    }),
                Action::make('unpublish')
                    ->label('Unpublish')
                    ->icon('heroicon-m-arrow-down-circle')
                    ->authorize(fn ($record) => auth()->user()?->can('publish', $record) ?? false)
                    ->visible(fn ($record) => $record->status === PageStatus::Published)
                    ->action(function ($record) {
                        app(PageService::class)->unpublishPage($record);
                        Notification::make()
                            ->title('Page unpublished successfully')
                            ->success()
                            ->send();
                    }),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-m-document-duplicate')
                    ->authorize(fn () => auth()->user()?->can('create', Page::class) ?? false)
                    ->action(function ($record) {
                        $newPage = app(PageService::class)->duplicatePage($record);
                        Notification::make()
                            ->title('Page duplicated successfully')
                            ->body("New page: {$newPage->title}")
                            ->success()
                            ->send();
                    }),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-m-archive-box')
                    ->authorize(fn ($record) => auth()->user()?->can('publish', $record) ?? false)
                    ->visible(fn ($record) => $record->status !== PageStatus::Archived)
                    ->action(function ($record) {
                        app(PageService::class)->archivePage($record);
                        Notification::make()
                            ->title('Page archived successfully')
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
            ->searchable()
            ->defaultSort('created_at', 'desc');

        return AdminListTable::apply($table);
    }
}
