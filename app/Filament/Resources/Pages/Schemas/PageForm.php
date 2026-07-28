<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Actions\GeneratePageSlugAction;
use App\Enums\PageContentWidth;
use App\Enums\PageStatus;
use App\Enums\PageVisibility;
use App\Filament\Support\Presentation\ContentBlockLinksPlaceholder;
use App\Models\Page;
use App\Services\Cms\StructuredPageContentService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('page_tabs')
                    ->tabs([
                        Tabs\Tab::make('General')
                            ->schema([
                                Section::make('Page Information')
                                    ->collapsible(false)
                                    ->schema([
                                        TextInput::make('title')
                                            ->required()
                                            ->maxLength(255)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, $set) {
                                                if (blank($state)) {
                                                    return;
                                                }
                                                $set('slug', app(GeneratePageSlugAction::class)->execute($state));
                                            }),
                                        TextInput::make('slug')
                                            ->required()
                                            ->unique('pages', 'slug', ignoreRecord: true)
                                            ->maxLength(255)
                                            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                                            ->helperText('URL-friendly identifier. Auto-generated from title.'),
                                    ]),

                                Section::make('Media')
                                    ->collapsible(false)
                                    ->schema([
                                        SpatieMediaLibraryFileUpload::make('featured_image')
                                            ->collection('featured-image')
                                            ->image()
                                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                                            ->maxSize(5120)
                                            ->helperText('Upload a featured image (max 5MB)'),
                                    ]),

                                Section::make('Page Template')
                                    ->description('Controls whether this page shows the site header, footer, and title banner.')
                                    ->collapsible(false)
                                    ->schema([
                                        Radio::make('template')
                                            ->label('Template')
                                            ->options([
                                                'default' => 'Default',
                                                'landing' => 'Landing Page',
                                                'blank' => 'Blank',
                                            ])
                                            ->descriptions([
                                                'default' => 'Global header + footer + page title hero banner. Best for standard content pages.',
                                                'landing' => 'No header or footer. Full-width clean canvas for sales or promo pages.',
                                                'blank' => 'Zero chrome. Raw block output only — for embeds or iframes.',
                                            ])
                                            ->default('default')
                                            ->inline(false),

                                        Select::make('layout')
                                            ->label('Content Width')
                                            ->helperText('Controls how wide the content area stretches inside the template.')
                                            ->options(PageContentWidth::options())
                                            ->native(false)
                                            ->default('default'),
                                    ]),
                            ]),

                        Tabs\Tab::make('Content')
                            ->schema([
                                Section::make('Main Content')
                                    ->description('Write your page content here.')
                                    ->collapsible(false)
                                    ->schema([
                                        RichEditor::make('content')
                                            ->label('')
                                            ->disabled(fn (?Page $record): bool => app(StructuredPageContentService::class)->usesStructuredContent($record))
                                            ->helperText(fn (?Page $record): string => app(StructuredPageContentService::class)->usesStructuredContent($record)
                                                ? "This page's content is managed through its design sections instead of this editor. You can still update its settings, SEO, publishing, and content width normally."
                                                : 'Use this editor for standard page content. Pages built with custom design sections are edited separately.')
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
                                            ->helperText('Optional short summary — used for cards, search results, RSS, and SEO fallback.'),
                                    ]),

                                Section::make('Advanced Layout')
                                    ->description('Use Content Blocks only when building advanced landing pages or reusable sections. Leave empty for standard pages.')
                                    ->collapsible(true)
                                    ->collapsed(true)
                                    ->schema([
                                        Placeholder::make('blocks_notice')
                                            ->label('Block Builder')
                                            ->content(fn (?Page $record) => ContentBlockLinksPlaceholder::content(
                                                $record,
                                                ownerQueryParam: 'page_id',
                                                blockableTypeFilterValue: Page::class,
                                                noun: 'Page',
                                            )),
                                    ]),
                            ]),

                        Tabs\Tab::make('Publishing')
                            ->schema([
                                Section::make('Publication Settings')
                                    ->collapsible(false)
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
                                            ->nullable()
                                            ->helperText('When should this page be published?'),
                                    ]),
                            ]),

                        Tabs\Tab::make('SEO')
                            ->schema([
                                Section::make('Search Engine Optimization')
                                    ->collapsible(false)
                                    ->schema([
                                        TextInput::make('meta_title')
                                            ->maxLength(70)
                                            ->helperText('Recommended: 50-70 characters'),
                                        Textarea::make('meta_description')
                                            ->maxLength(160)
                                            ->rows(3)
                                            ->helperText('Recommended: 150-160 characters'),
                                        Textarea::make('meta_keywords')
                                            ->maxLength(255)
                                            ->rows(2)
                                            ->helperText('Comma-separated keywords'),
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
