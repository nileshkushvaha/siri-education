<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Models\Page as PageModel;
use App\Settings\GeneralSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

class GeneralSettingsPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use HasSettingsSectionBreadcrumb;
    use LogsSettingsUpdates;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'General';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'settings/general';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'General Settings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'General Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Configure your application\'s global information, branding, and localization.';
    }

    public function mount(): void
    {
        $settings = app(GeneralSettings::class);

        $this->form->fill([
            'app_name' => $settings->app_name,
            'app_short_name' => $settings->app_short_name,
            'organization_name' => $settings->organization_name,
            'support_email' => $settings->support_email,
            'support_phone' => $settings->support_phone,
            'website_url' => $settings->website_url,
            'address' => $settings->address,
            'logo' => $settings->logo,
            'favicon' => $settings->favicon,
            'header_top_bar_enabled' => $settings->header_top_bar_enabled,
            'facebook_url' => $settings->facebook_url,
            'instagram_url' => $settings->instagram_url,
            'x_url' => $settings->x_url,
            'youtube_url' => $settings->youtube_url,
            'default_timezone' => $settings->default_timezone,
            'default_currency' => $settings->default_currency,
            'footer_copyright' => $settings->footer_copyright,
            'footer_text' => $settings->footer_text,
            'homepage_display' => $settings->homepage_display ?? 'template',
            'homepage_id' => $settings->homepage_id,
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
                            ->label('Save Settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),

                        Action::make('reset')
                            ->label('Reset to Defaults')
                            ->color('gray')
                            ->requiresConfirmation()
                            ->modalHeading('Reset to defaults?')
                            ->modalDescription('This will restore all general settings to their default values.')
                            ->action('resetDefaults'),
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

                // ── Application Information ─────────────────────── full width
                Section::make('Application Information')
                    ->description('Basic information about your application.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('app_name')
                                ->label('Application Name')
                                ->required()
                                ->maxLength(150)
                                ->placeholder('SIRI Education'),

                            TextInput::make('app_short_name')
                                ->label('Short Name')
                                ->maxLength(50)
                                ->placeholder('SIRI'),

                            TextInput::make('organization_name')
                                ->label('Organization Name')
                                ->maxLength(150),
                        ]),

                        Grid::make(3)->schema([
                            TextInput::make('support_email')
                                ->label('Support Email')
                                ->email()
                                ->required()
                                ->maxLength(150),

                            TextInput::make('support_phone')
                                ->label('Support Phone')
                                ->tel()
                                ->maxLength(30),

                            TextInput::make('website_url')
                                ->label('Website URL')
                                ->url()
                                ->maxLength(255)
                                ->placeholder('https://example.com'),
                        ]),

                        Textarea::make('address')
                            ->label('Address')
                            ->rows(2)
                            ->maxLength(500),
                    ]),

                // ── Branding ──────────────────────────────────────── left
                Section::make('Branding')
                    ->description('Upload your logo, dark logo, and favicon.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            FileUpload::make('logo')
                                ->label('Logo (Light)')
                                ->image()
                                ->disk('public')
                                ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml'])
                                ->maxSize(2048)
                                ->directory('settings/branding')
                                ->imagePreviewHeight('80')
                                ->helperText('PNG, JPG or SVG. Max 2MB.'),

                            FileUpload::make('favicon')
                                ->label('Favicon')
                                ->image()
                                ->disk('public')
                                ->acceptedFileTypes(['image/x-icon', 'image/png'])
                                ->maxSize(512)
                                ->directory('settings/branding')
                                ->imagePreviewHeight('80')
                                ->helperText('ICO or PNG. Max 512KB.'),
                        ]),
                    ]),

                Section::make('Header Top Bar')
                    ->description('Show contact details and optional social links above the public navigation.')
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('header_top_bar_enabled')
                            ->label('Show header top bar')
                            ->helperText('The support phone and email come from Application Information above.')
                            ->live(),

                        Grid::make(4)->schema([
                            TextInput::make('facebook_url')
                                ->label('Facebook URL')
                                ->url()
                                ->maxLength(255)
                                ->placeholder('https://facebook.com/...'),

                            TextInput::make('instagram_url')
                                ->label('Instagram URL')
                                ->url()
                                ->maxLength(255)
                                ->placeholder('https://instagram.com/...'),

                            TextInput::make('x_url')
                                ->label('X URL')
                                ->url()
                                ->maxLength(255)
                                ->placeholder('https://x.com/...'),

                            TextInput::make('youtube_url')
                                ->label('YouTube URL')
                                ->url()
                                ->maxLength(255)
                                ->placeholder('https://youtube.com/@...'),
                        ])->visible(fn ($get) => (bool) $get('header_top_bar_enabled')),
                    ]),

                // ── Localization ──────────────────────────────────── right
                Section::make('Localization')
                    ->description('Default timezone, language, and date/time formats. Country and locale-switching defaults live under Platform Foundation Settings.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('default_timezone')
                                ->label('Default Timezone')
                                ->options(
                                    collect(\DateTimeZone::listIdentifiers())
                                        ->mapWithKeys(fn ($tz) => [$tz => $tz])
                                        ->all()
                                )
                                ->searchable()
                                ->native(false)
                                ->required(),
                        ]),
                    ]),

                // ── Application ───────────────────────────────────── left
                Section::make('Application')
                    ->description('Default currency for pricing and wallet balances.')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('default_currency')
                                ->label('Default Currency')
                                ->options([
                                    'INR' => 'INR — Indian Rupee (₹)',
                                    'USD' => 'USD — US Dollar ($)',
                                    'EUR' => 'EUR — Euro (€)',
                                    'GBP' => 'GBP — British Pound (£)',
                                    'AED' => 'AED — UAE Dirham (د.إ)',
                                    'SGD' => 'SGD — Singapore Dollar (S$)',
                                    'AUD' => 'AUD — Australian Dollar (A$)',
                                    'CAD' => 'CAD — Canadian Dollar (C$)',
                                ])
                                ->native(false)
                                ->searchable()
                                ->required(),
                        ]),
                    ]),

                // ── Footer ────────────────────────────────────────── right
                Section::make('Footer')
                    ->description('Text displayed in your application\'s footer.')
                    ->schema([
                        TextInput::make('footer_copyright')
                            ->label('Copyright Text')
                            ->maxLength(255)
                            ->placeholder('© 2025 SIRI Education. All rights reserved.'),

                        Textarea::make('footer_text')
                            ->label('Footer Text')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Optional additional footer text or legal disclaimer.'),
                    ]),

                // ── Reading ───────────────────────────────────────── full width
                Section::make('Reading')
                    ->description('Control what visitors see on your homepage — your custom template or any published page.')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('homepage_display')
                            ->label('Your homepage displays')
                            ->options([
                                'template' => '🏠  Default template (built-in homepage)',
                                'static_page' => '📄  A static page',
                            ])
                            ->native(false)
                            ->live()
                            ->required()
                            ->helperText('Choose "Default template" to use your custom-coded homepage, or "A static page" to pick any published CMS page.'),

                        Select::make('homepage_id')
                            ->label('Homepage')
                            ->placeholder('— Select a page —')
                            ->options(fn () => PageModel::query()
                                ->published()
                                ->orderBy('title')
                                ->get()
                                ->mapWithKeys(fn ($page) => [(string) $page->id => $page->title])
                                ->all()
                            )
                            ->searchable()
                            ->native(false)
                            ->visible(fn ($get) => $get('homepage_display') === 'static_page')
                            ->required(fn ($get) => $get('homepage_display') === 'static_page')
                            ->helperText('This page will be shown at your site root ( / ).'),
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

        $saved = $this->saveSettingsWithAudit(GeneralSettings::class, 'settings', function (GeneralSettings $settings) use ($data): void {
            $settings->app_name = $data['app_name'];
            $settings->app_short_name = $data['app_short_name'] ?? null;
            $settings->organization_name = $data['organization_name'] ?? null;
            $settings->support_email = $data['support_email'];
            $settings->support_phone = $data['support_phone'] ?? null;
            $settings->website_url = $data['website_url'] ?? null;
            $settings->address = $data['address'] ?? null;
            $settings->logo = $data['logo'] ?? $settings->logo;
            $settings->favicon = $data['favicon'] ?? $settings->favicon;
            $settings->header_top_bar_enabled = (bool) ($data['header_top_bar_enabled'] ?? false);
            $settings->facebook_url = $data['facebook_url'] ?? null;
            $settings->instagram_url = $data['instagram_url'] ?? null;
            $settings->x_url = $data['x_url'] ?? null;
            $settings->youtube_url = $data['youtube_url'] ?? null;
            $settings->default_timezone = $data['default_timezone'];
            $settings->default_currency = $data['default_currency'];
            $settings->footer_copyright = $data['footer_copyright'] ?? null;
            $settings->footer_text = $data['footer_text'] ?? null;
            $settings->homepage_display = $data['homepage_display'] ?? 'template';
            $settings->homepage_id = ($data['homepage_display'] ?? 'template') === 'static_page'
                                            ? ($data['homepage_id'] ?? null)
                                            : null;
        });

        if (! $saved) {
            return;
        }

        Notification::make()
            ->title('General settings saved')
            ->success()
            ->send();
    }

    public function resetDefaults(): void
    {
        $saved = $this->saveSettingsWithAudit(GeneralSettings::class, 'settings', function (GeneralSettings $settings): void {
            $settings->default_timezone = 'Asia/Kolkata';
            $settings->default_currency = 'INR';
            $settings->header_top_bar_enabled = false;
            $settings->facebook_url = null;
            $settings->instagram_url = null;
            $settings->x_url = null;
            $settings->youtube_url = null;
            $settings->homepage_display = 'template';
            $settings->homepage_id = null;
        });

        if (! $saved) {
            return;
        }

        $this->mount(); // reload form

        Notification::make()
            ->title('Settings reset to defaults')
            ->success()
            ->send();
    }
}
