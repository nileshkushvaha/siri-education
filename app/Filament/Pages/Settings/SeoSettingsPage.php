<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

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
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class SeoSettingsPage extends Page
{
    use HasSettingsAccess;
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

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '/admin/settings/general' => 'Settings',
            '#' => 'SEO',
        ];
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

                // ── SEO Meta ──────────────────────────────────── full width
                Section::make('SEO Meta')
                    ->description('Default meta tags applied to all pages without specific overrides.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('meta_title')
                                ->label('Meta Title')
                                ->maxLength(70)
                                ->helperText('Max 70 characters. Shown in browser tab and search results.')
                                ->suffixAction(
                                    Action::make('count')
                                        ->label(fn ($state) => strlen($state ?? '').'/70')
                                        ->disabled()
                                ),

                            TextInput::make('meta_keywords')
                                ->label('Meta Keywords')
                                ->maxLength(255)
                                ->helperText('Comma-separated keywords (less important for modern SEO).'),
                        ]),

                        Textarea::make('meta_description')
                            ->label('Meta Description')
                            ->rows(3)
                            ->maxLength(160)
                            ->helperText('Max 160 characters. Shown in search engine results.'),

                        Grid::make(2)->schema([
                            Select::make('robots')
                                ->label('Robots Directive')
                                ->options([
                                    'index,follow' => 'index, follow (default)',
                                    'noindex,follow' => 'noindex, follow',
                                    'index,nofollow' => 'index, nofollow',
                                    'noindex,nofollow' => 'noindex, nofollow',
                                ])
                                ->native(false)
                                ->required(),

                            TextInput::make('canonical_url')
                                ->label('Canonical URL')
                                ->url()
                                ->maxLength(255)
                                ->placeholder('https://example.com'),
                        ]),
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
            $settings->meta_title = $data['meta_title'] ?? null;
            $settings->meta_description = $data['meta_description'] ?? null;
            $settings->meta_keywords = $data['meta_keywords'] ?? null;
            $settings->robots = $data['robots'];
            $settings->canonical_url = $data['canonical_url'] ?? null;
            $settings->google_search_console_verification = $data['google_search_console_verification'] ?? null;
            $settings->google_analytics_id = $data['google_analytics_id'] ?? null;
            $settings->google_tag_manager_id = $data['google_tag_manager_id'] ?? null;
            $settings->facebook_pixel_id = $data['facebook_pixel_id'] ?? null;
            $settings->og_image = $data['og_image'] ?? $settings->og_image;
            $settings->twitter_card = $data['twitter_card'];
        });

        if (! $saved) {
            return;
        }

        Notification::make()
            ->title('SEO settings saved')
            ->success()
            ->send();
    }
}
