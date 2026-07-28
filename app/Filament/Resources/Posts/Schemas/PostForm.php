<?php

namespace App\Filament\Resources\Posts\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Filament\Support\Presentation\ContentBlockLinksPlaceholder;
use App\Models\Post;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;

class PostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('post_tabs')
                    ->tabs([
                        Tabs\Tab::make('General')
                            ->schema([
                                Section::make('Post Information')
                                    ->schema([
                                        TextInput::make('title')
                                            ->required()
                                            ->maxLength(255)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, $set): void {
                                                if (blank($state)) {
                                                    return;
                                                }

                                                $set('slug', app(GeneratePageSlugAction::class)->execute($state, null, Post::class));
                                            }),
                                        TextInput::make('slug')
                                            ->required()
                                            ->unique('posts', 'slug', ignoreRecord: true)
                                            ->maxLength(255)
                                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                                            ->helperText('URL-friendly identifier. Auto-generated from title.'),
                                        SpatieMediaLibraryFileUpload::make('featured_image')
                                            ->collection('featured-image')
                                            ->image()
                                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                                            ->maxSize(5120)
                                            ->helperText('Upload a featured image (max 5MB).'),
                                        Select::make('author_id')
                                            ->label('Author')
                                            ->relationship('author', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->default(fn () => auth()->id())
                                            ->required(),
                                        Select::make('categories')
                                            ->label('Categories')
                                            ->relationship('categories', 'name')
                                            ->multiple()
                                            ->searchable()
                                            ->preload(),
                                        Select::make('tags')
                                            ->label('Tags')
                                            ->relationship('tags', 'name')
                                            ->multiple()
                                            ->searchable()
                                            ->preload(),
                                        Select::make('relatedPosts')
                                            ->label('Related Posts')
                                            ->relationship('relatedPosts', 'title')
                                            ->multiple()
                                            ->searchable()
                                            ->preload(),
                                        Toggle::make('featured')
                                            ->default(false),
                                        Toggle::make('allow_comments')
                                            ->default(true),
                                    ]),
                            ]),

                        Tabs\Tab::make('Content')
                            ->schema([
                                Section::make('Main Content')
                                    ->description('Write your post content here. This is the primary editable area.')
                                    ->collapsible(false)
                                    ->schema([
                                        RichEditor::make('content')
                                            ->label('')
                                            ->toolbarButtons([
                                                'bold',
                                                'italic',
                                                'underline',
                                                'strike',
                                                'link',
                                                'h2',
                                                'h3',
                                                'bulletList',
                                                'orderedList',
                                                'blockquote',
                                                'codeBlock',
                                                'table',
                                                'attachFiles',
                                                'undo',
                                                'redo',
                                            ])
                                            ->extraAttributes(['style' => 'min-height:500px'])
                                            ->columnSpanFull(),

                                        Textarea::make('excerpt')
                                            ->label('Excerpt')
                                            ->rows(3)
                                            ->maxLength(500)
                                            ->helperText('Optional short summary — used for blog listing cards, search results, RSS, and SEO fallback.'),
                                    ]),

                                Section::make('Advanced Layout')
                                    ->description('Use Content Blocks only when building advanced layouts or reusable sections. Leave empty for standard posts.')
                                    ->collapsible(true)
                                    ->collapsed(true)
                                    ->schema([
                                        Placeholder::make('blocks_notice')
                                            ->label('Block Builder')
                                            ->content(fn (?Post $record) => ContentBlockLinksPlaceholder::content(
                                                $record,
                                                ownerQueryParam: 'post_id',
                                                blockableTypeFilterValue: Post::class,
                                                noun: 'Post',
                                            )),
                                    ]),
                            ]),

                        Tabs\Tab::make('Publishing')
                            ->schema([
                                Section::make('Publication Settings')
                                    ->schema([
                                        Select::make('status')
                                            ->options(PageStatus::class)
                                            ->default(PageStatus::Draft)
                                            ->native(false)
                                            ->required(),
                                        Select::make('visibility')
                                            ->options(PageVisibility::class)
                                            ->default(PageVisibility::Private)
                                            ->native(false)
                                            ->required(),
                                        DateTimePicker::make('published_at')
                                            ->nullable(),
                                        TextInput::make('reading_time')
                                            ->label('Reading Time (minutes)')
                                            ->numeric()
                                            ->disabled()
                                            ->dehydrated(false),
                                    ]),
                            ]),

                        Tabs\Tab::make('SEO')
                            ->schema([
                                Section::make('Search Engine Optimization')
                                    ->schema([
                                        TextInput::make('meta_title')
                                            ->maxLength(70),
                                        Textarea::make('meta_description')
                                            ->maxLength(160)
                                            ->rows(3),
                                        Textarea::make('meta_keywords')
                                            ->maxLength(255)
                                            ->rows(2),
                                        TextInput::make('canonical_url')
                                            ->url()
                                            ->nullable(),
                                        Select::make('robots')
                                            ->options([
                                                'index, follow' => 'Index & Follow',
                                                'noindex, follow' => 'No Index, Follow',
                                                'index, nofollow' => 'Index, No Follow',
                                                'noindex, nofollow' => 'No Index, No Follow',
                                            ])
                                            ->default('index, follow')
                                            ->native(false),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
