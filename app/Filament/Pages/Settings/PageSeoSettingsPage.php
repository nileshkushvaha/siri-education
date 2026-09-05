<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Content\SEO\SeoRoute;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Settings\PageSeoSettings;
use BackedEnum;
use Filament\Actions\Action;
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

class PageSeoSettingsPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use HasSettingsSectionBreadcrumb;
    use LogsSettingsUpdates;

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
        return 'Meta title and description for the standard public pages. Leave a field empty to keep the page\'s built-in text; the home page also falls back to the SEO Settings defaults.';
    }

    public function mount(): void
    {
        $pages = app(PageSeoSettings::class)->pages;

        $this->form->fill([
            'pages' => collect(SeoRoute::cases())
                ->mapWithKeys(fn (SeoRoute $route): array => [$route->value => [
                    'meta_title' => $pages[$route->value]['meta_title'] ?? null,
                    'meta_description' => $pages[$route->value]['meta_description'] ?? null,
                ]])
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
            Grid::make(2)->schema(
                collect(SeoRoute::cases())
                    ->map(fn (SeoRoute $route) => Section::make($route->label())
                        ->description(url($route->path()))
                        ->schema([
                            TextInput::make("pages.{$route->value}.meta_title")
                                ->label('Meta Title')
                                ->maxLength(70)
                                ->helperText('Max 70 characters. Shown in the browser tab and search results.'),
                            Textarea::make("pages.{$route->value}.meta_description")
                                ->label('Meta Description')
                                ->rows(3)
                                ->maxLength(160)
                                ->helperText('Max 160 characters. Shown in search engine results.'),
                        ]))
                    ->all(),
            ),
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
            $settings->pages = collect(SeoRoute::cases())
                ->mapWithKeys(fn (SeoRoute $route): array => [$route->value => [
                    'meta_title' => filled($data['pages'][$route->value]['meta_title'] ?? null) ? trim((string) $data['pages'][$route->value]['meta_title']) : null,
                    'meta_description' => filled($data['pages'][$route->value]['meta_description'] ?? null) ? trim((string) $data['pages'][$route->value]['meta_description']) : null,
                ]])
                ->all();
        });

        if (! $saved) {
            return;
        }

        Notification::make()
            ->title('Page SEO saved')
            ->success()
            ->send();
    }
}
