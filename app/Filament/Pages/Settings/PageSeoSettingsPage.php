<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Content\SEO\SeoRoute;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Settings\PageSeoSettings;
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

/**
 * One page at a time: a selector picks the public page, and a single set
 * of SEO fields is shown for it. Every page's values stay loaded in
 * `data.pages`; only the chosen page's section is visible, and save()
 * merges the visible page over the stored entries so switching pages
 * never discards the others.
 */
class PageSeoSettingsPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use HasSettingsSectionBreadcrumb;
    use LogsSettingsUpdates;

    private const array FIELDS = ['meta_title', 'meta_description', 'meta_keywords', 'canonical_url', 'og_image'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $navigationLabel = 'Page SEO';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'settings/page-seo';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'Page SEO';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Page SEO';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Choose a page, then set its search and social sharing metadata. Empty fields keep the page\'s built-in text; the home page also falls back to SEO Settings.';
    }

    public function mount(): void
    {
        $pages = app(PageSeoSettings::class)->pages;

        $this->form->fill([
            'selected_page' => SeoRoute::Home->value,
            'pages' => collect(SeoRoute::cases())
                ->mapWithKeys(fn (SeoRoute $route): array => [$route->value => $this->entryFor($pages, $route)])
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
                            ->label('Save Page SEO')
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
            Select::make('selected_page')
                ->label('Page')
                ->options(collect(SeoRoute::cases())->mapWithKeys(fn (SeoRoute $route): array => [$route->value => $route->label().'  ·  '.$route->path()])->all())
                ->native(false)
                ->required()
                ->live()
                ->dehydrated(false),

            ...collect(SeoRoute::cases())
                ->map(fn (SeoRoute $route) => Section::make($route->label())
                    ->description(url($route->path()))
                    ->visible(fn (Get $get): bool => $get('selected_page') === $route->value)
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make("pages.{$route->value}.meta_title")
                                ->label('Meta Title')
                                ->maxLength(70)
                                ->helperText('Max 70 characters. Browser tab and search result headline.'),
                            TextInput::make("pages.{$route->value}.meta_keywords")
                                ->label('Meta Keywords')
                                ->maxLength(255)
                                ->helperText('Comma-separated. Falls back to the SEO Settings keywords.'),
                        ]),
                        Textarea::make("pages.{$route->value}.meta_description")
                            ->label('Meta Description')
                            ->rows(3)
                            ->maxLength(160)
                            ->helperText('Max 160 characters. Search result snippet and social sharing description.'),
                        Grid::make(2)->schema([
                            TextInput::make("pages.{$route->value}.canonical_url")
                                ->label('Canonical URL')
                                ->url()
                                ->maxLength(255)
                                ->placeholder(url($route->path()))
                                ->helperText('Leave empty to use the page\'s own URL.'),
                            FileUpload::make("pages.{$route->value}.og_image")
                                ->label('Open Graph Image')
                                ->image()
                                ->disk('public')
                                ->acceptedFileTypes(['image/png', 'image/jpeg'])
                                ->maxSize(2048)
                                ->directory('settings/seo/pages')
                                ->imagePreviewHeight('120')
                                ->helperText('PNG or JPG, max 2MB, ideally 1200×630. Falls back to the default OG image.'),
                        ]),
                    ]))
                ->all(),
        ]);
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        $saved = $this->saveSettingsWithAudit(PageSeoSettings::class, 'settings', function (PageSeoSettings $settings) use ($data): void {
            $stored = $settings->pages;

            // Only the selected page's fields are part of the submitted
            // state (hidden sections are not dehydrated), so merge over
            // what is stored rather than replacing the whole map.
            foreach (SeoRoute::cases() as $route) {
                $source = array_key_exists($route->value, $data['pages'] ?? []) ? $data['pages'] : $stored;
                $stored[$route->value] = $this->entryFor($source, $route);
            }

            $settings->pages = $stored;
        });

        if (! $saved) {
            return;
        }

        Notification::make()
            ->title('Page SEO saved')
            ->success()
            ->send();
    }

    /**
     * Normalises one page's entry: every field present, blank → null.
     *
     * @param  array<string, mixed>  $pages
     * @return array<string, string|null>
     */
    private function entryFor(array $pages, SeoRoute $route): array
    {
        $entry = $pages[$route->value] ?? [];
        $normalised = [];

        foreach (self::FIELDS as $field) {
            $normalised[$field] = filled($entry[$field] ?? null) ? trim((string) $entry[$field]) : null;
        }

        return $normalised;
    }
}
