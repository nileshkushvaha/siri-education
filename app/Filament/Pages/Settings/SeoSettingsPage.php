<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Content\SEO\SeoRoute;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Services\CmsCacheService;
use App\Settings\SeoSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form as FormComponent;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class SeoSettingsPage extends Page
{
    private const array PAGE_FIELDS = ['meta_title', 'meta_description', 'meta_keywords', 'canonical_url', 'og_image', 'robots'];

    private const array ROBOTS_OPTIONS = [
        'index,follow' => 'index, follow',
        'noindex,follow' => 'noindex, follow',
        'index,nofollow' => 'index, nofollow',
        'noindex,nofollow' => 'noindex, nofollow',
    ];

    private const string DEFAULTS = 'defaults';

    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use HasSettingsSectionBreadcrumb;
    use LogsSettingsUpdates;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $navigationLabel = 'SEO';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'settings/seo';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'SEO Settings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'SEO Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Manage your site\'s SEO metadata, analytics integrations, and social sharing settings.';
    }

    public function mount(): void
    {
        $settings = app(SeoSettings::class);

        $this->form->fill([
            'meta_title' => $settings->meta_title,
            'meta_description' => $settings->meta_description,
            'meta_keywords' => $settings->meta_keywords,
            'robots' => $settings->robots,
            'canonical_url' => $settings->canonical_url,
            'google_search_console_verification' => $settings->google_search_console_verification,
            'google_analytics_id' => $settings->google_analytics_id,
            'google_tag_manager_id' => $settings->google_tag_manager_id,
            'facebook_pixel_id' => $settings->facebook_pixel_id,
            'og_image' => $settings->og_image,
            'twitter_card' => $settings->twitter_card,
            'selected_page' => self::DEFAULTS,
            'pages' => collect(SeoRoute::cases())
                ->mapWithKeys(fn (SeoRoute $route): array => [$route->value => $this->pageEntry($settings->pages, $route)])
                ->all(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            FormComponent::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    ActionsComponent::make([
                        Action::make('save')
                            ->label('Save SEO Settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([

                // ── Search & social metadata ──────────────────── full width
                Section::make('Search & social metadata')
                    ->description('Choose Site defaults or a public page. Page fields override that page; empty page fields keep its built-in text, and anything still missing falls back to Site defaults.')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('selected_page')
                            ->label('Page')
                            ->options([
                                self::DEFAULTS => 'Site defaults  ·  used when a page has nothing of its own',
                                ...collect(SeoRoute::cases())->mapWithKeys(fn (SeoRoute $route): array => [$route->value => $route->label().'  ·  '.$route->path()])->all(),
                            ])
                            ->native(false)
                            ->required()
                            ->live()
                            ->dehydrated(false),

                        Grid::make(2)
                            ->visible(fn (Get $get): bool => $get('selected_page') === self::DEFAULTS)
                            ->schema([
                                TextInput::make('meta_title')
                                    ->label('Default Meta Title')
                                    ->maxLength(70)
                                    ->helperText('Max 70 characters. Used only when a page has no title of its own.'),
                                TextInput::make('meta_keywords')
                                    ->label('Default Meta Keywords')
                                    ->maxLength(255)
                                    ->helperText('Comma-separated. Used when a page sets no keywords.'),
                                Textarea::make('meta_description')
                                    ->label('Default Meta Description')
                                    ->rows(3)
                                    ->maxLength(160)
                                    ->columnSpanFull()
                                    ->helperText('Max 160 characters. Used only when a page has no description of its own.'),
                                Select::make('robots')
                                    ->label('Robots Directive')
                                    ->options(self::ROBOTS_OPTIONS)
                                    ->native(false)
                                    ->required()
                                    ->helperText('Site-wide: robots.txt and the robots meta tag of every page without its own directive.'),
                            ]),

                        ...collect(SeoRoute::cases())
                            ->map(fn (SeoRoute $route) => Grid::make(2)
                                ->visible(fn (Get $get): bool => $get('selected_page') === $route->value)
                                ->schema([
                                    TextInput::make("pages.{$route->value}.meta_title")
                                        ->label('Meta Title')
                                        ->maxLength(70)
                                        ->helperText('Max 70 characters. Browser tab and search result headline.'),
                                    TextInput::make("pages.{$route->value}.meta_keywords")
                                        ->label('Meta Keywords')
                                        ->maxLength(255)
                                        ->helperText('Comma-separated. Falls back to the default keywords above.'),
                                    Textarea::make("pages.{$route->value}.meta_description")
                                        ->label('Meta Description')
                                        ->rows(3)
                                        ->maxLength(160)
                                        ->columnSpanFull()
                                        ->helperText('Max 160 characters. Search result snippet and social sharing description.'),
                                    TextInput::make("pages.{$route->value}.canonical_url")
                                        ->label('Canonical URL')
                                        ->url()
                                        ->maxLength(255)
                                        ->placeholder(url($route->path()))
                                        ->helperText('Leave empty to use the page\'s own URL.'),
                                    Select::make("pages.{$route->value}.robots")
                                        ->label('Robots Directive')
                                        ->options(self::ROBOTS_OPTIONS)
                                        ->native(false)
                                        ->placeholder('Use site default')
                                        ->helperText('Robots meta tag for this page only.'),
                                    FileUpload::make("pages.{$route->value}.og_image")
                                        ->label('Open Graph Image')
                                        ->image()
                                        ->disk('public')
                                        ->acceptedFileTypes(['image/png', 'image/jpeg'])
                                        ->maxSize(2048)
                                        ->directory('settings/seo/pages')
                                        ->imagePreviewHeight('120')
                                        ->helperText('PNG or JPG, max 2MB, ideally 1200×630. Falls back to the default OG image.'),
                                ]))
                            ->all(),
                    ]),

                // ── Verification & Analytics ──────────────────────── left
                Section::make('Verification & Analytics')
                    ->description('Connect your site to Google, Tag Manager, and other analytics tools.')
                    ->schema([
                        TextInput::make('google_search_console_verification')
                            ->label('Google Search Console Verification')
                            ->maxLength(255)
                            ->placeholder('google-site-verification=...')
                            ->helperText('Paste the verification meta tag content value.'),

                        Grid::make(2)->schema([
                            TextInput::make('google_analytics_id')
                                ->label('Google Analytics ID')
                                ->maxLength(30)
                                ->placeholder('G-XXXXXXXXXX'),

                            TextInput::make('google_tag_manager_id')
                                ->label('Google Tag Manager ID')
                                ->maxLength(30)
                                ->placeholder('GTM-XXXXXXX'),
                        ]),

                        TextInput::make('facebook_pixel_id')
                            ->label('Facebook Pixel ID')
                            ->maxLength(30)
                            ->placeholder('000000000000000'),
                    ]),

                // ── Social Sharing ────────────────────────────────── right
                Section::make('Social Sharing (Open Graph)')
                    ->description('Controls how your pages appear when shared on social media.')
                    ->schema([
                        FileUpload::make('og_image')
                            ->label('Default OG Image')
                            ->image()
                            ->disk('public')
                            ->acceptedFileTypes(['image/png', 'image/jpeg'])
                            ->maxSize(2048)
                            ->directory('settings/seo')
                            ->imagePreviewHeight('120')
                            ->helperText('PNG or JPG, max 2MB. Recommended: 1200×630px.'),

                        Select::make('twitter_card')
                            ->label('Twitter Card Type')
                            ->options([
                                'summary' => 'Summary',
                                'summary_large_image' => 'Summary with Large Image',
                                'app' => 'App',
                                'player' => 'Player',
                            ])
                            ->native(false)
                            ->required(),
                    ]),

            ]),
        ]);
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        $saved = $this->saveSettingsWithAudit(SeoSettings::class, 'settings', function (SeoSettings $settings) use ($data): void {
            // The Site defaults grid is only dehydrated while it is the
            // selected entry; otherwise keep what is stored.
            if (array_key_exists('robots', $data)) {
                $settings->meta_title = filled($data['meta_title'] ?? null) ? trim((string) $data['meta_title']) : null;
                $settings->meta_description = filled($data['meta_description'] ?? null) ? trim((string) $data['meta_description']) : null;
                $settings->meta_keywords = filled($data['meta_keywords'] ?? null) ? trim((string) $data['meta_keywords']) : null;
                $settings->robots = $data['robots'];
            }
            $settings->google_search_console_verification = $data['google_search_console_verification'] ?? null;
            $settings->google_analytics_id = $data['google_analytics_id'] ?? null;
            $settings->google_tag_manager_id = $data['google_tag_manager_id'] ?? null;
            $settings->facebook_pixel_id = $data['facebook_pixel_id'] ?? null;
            $settings->og_image = $data['og_image'] ?? $settings->og_image;
            $settings->twitter_card = $data['twitter_card'];

            // Only the selected page's fields are in the submitted state
            // (hidden grids are not dehydrated), so merge over what is
            // stored rather than replacing the whole map.
            $pages = $settings->pages;

            foreach (SeoRoute::cases() as $route) {
                $source = array_key_exists($route->value, $data['pages'] ?? []) ? $data['pages'] : $pages;
                $entry = $this->pageEntry($source, $route);

                if (array_filter($entry) === []) {
                    unset($pages[$route->value]);
                } else {
                    $pages[$route->value] = $entry;
                }
            }

            $settings->pages = $pages;
        });

        if (! $saved) {
            return;
        }

        // robots.txt and the sitemap are cached under a discovery-versioned key
        // (CmsCacheService), with robots.txt held for an hour by default. Now
        // that robots.txt reflects the Robots setting, saving has to invalidate
        // that cache — otherwise switching the site to "noindex" would appear
        // to work while robots.txt kept serving "Allow: /" for up to an hour.
        app(CmsCacheService::class)->bumpDiscoveryVersion();

        Notification::make()
            ->title('SEO settings saved')
            ->success()
            ->send();
    }

    /**
     * Normalises one page's override entry: every field present, blank → null.
     *
     * @param  array<string, mixed>  $pages
     * @return array<string, string|null>
     */
    private function pageEntry(array $pages, SeoRoute $route): array
    {
        $entry = $pages[$route->value] ?? [];
        $normalised = [];

        foreach (self::PAGE_FIELDS as $field) {
            $normalised[$field] = filled($entry[$field] ?? null) ? trim((string) $entry[$field]) : null;
        }

        return $normalised;
    }
}
