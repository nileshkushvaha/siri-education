<?php

namespace App\Filament\Resources\Posts\Tables;

use App\Enums\PageStatus;
use App\Filament\Support\Tables\AdminListTable;
use App\Models\Post;
use App\Services\PostService;
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
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PostsTable
{
    public static function configure(Table $table): Table
    {
        $table
            ->columns([
                ImageColumn::make('featured_image_url')
                    ->label('Featured Image')
                    ->default('/images/placeholder.png')
                    ->circular(),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('author.name')
                    ->label('Author')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PageStatus $state): string => $state->color()),
                ToggleColumn::make('featured')
                    ->label('Featured')
                    ->disabled(fn ($record): bool => ! (auth()->user()?->can('update', $record) ?? false)),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reading_time')
                    ->label('Reading Time')
                    ->formatStateUsing(fn ($state): string => max(1, (int) $state).' min')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PageStatus::class)
                    ->multiple(),
                SelectFilter::make('author_id')
                    ->label('Author')
                    ->relationship('author', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('categories')
                    ->label('Category')
                    ->relationship('categories', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('tags')
                    ->label('Tag')
                    ->relationship('tags', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('featured')
                    ->label('Featured'),
                Filter::make('published')
                    ->query(fn (Builder $query) => $query->where('status', PageStatus::Published)),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-m-eye')
                    ->authorize(fn (Post $record) => auth()->user()?->can('view', $record) ?? false)
                    ->url(fn (Post $record) => route('admin.posts.preview', $record))
                    ->openUrlInNewTab(),
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-m-arrow-up-circle')
                    ->authorize(fn (Post $record) => auth()->user()?->can('publish', $record) ?? false)
                    ->visible(fn (Post $record) => $record->status !== PageStatus::Published)
                    ->action(function (Post $record): void {
                        app(PostService::class)->publishPost($record);
                        Notification::make()->title('Post published successfully')->success()->send();
                    }),
                Action::make('unpublish')
                    ->label('Unpublish')
                    ->icon('heroicon-m-arrow-down-circle')
                    ->authorize(fn (Post $record) => auth()->user()?->can('publish', $record) ?? false)
                    ->visible(fn (Post $record) => $record->status === PageStatus::Published)
                    ->action(function (Post $record): void {
                        app(PostService::class)->unpublishPost($record);
                        Notification::make()->title('Post unpublished successfully')->success()->send();
                    }),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-m-document-duplicate')
                    ->authorize(fn () => auth()->user()?->can('create', Post::class) ?? false)
                    ->action(function (Post $record): void {
                        $newPost = app(PostService::class)->duplicatePost($record->loadMissing('blocks'));
                        Notification::make()
                            ->title('Post duplicated successfully')
                            ->body("New post: {$newPost->title}")
                            ->success()
                            ->send();
                    }),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-m-archive-box')
                    ->authorize(fn (Post $record) => auth()->user()?->can('publish', $record) ?? false)
                    ->visible(fn (Post $record) => $record->status !== PageStatus::Archived)
                    ->action(function (Post $record): void {
                        app(PostService::class)->archivePost($record);
                        Notification::make()->title('Post archived successfully')->success()->send();
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
            ->searchable(['title', 'slug', 'excerpt', 'author.name'])
            ->defaultSort('created_at', 'desc');

        return AdminListTable::apply($table);
    }
}
