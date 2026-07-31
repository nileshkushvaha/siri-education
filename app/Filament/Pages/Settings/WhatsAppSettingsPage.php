<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Settings\WhatsAppSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
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

class WhatsAppSettingsPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use HasSettingsSectionBreadcrumb;
    use LogsSettingsUpdates;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'WhatsApp';

    protected static string|\UnitEnum|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'settings/whatsapp';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'WhatsApp Settings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'WhatsApp Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Configure the floating WhatsApp click-to-chat button shown on the public site.';
    }

    public function mount(): void
    {
        $settings = app(WhatsAppSettings::class);

        $this->form->fill([
            'enabled' => $settings->enabled,
            'number' => $settings->number,
            'default_message' => $settings->default_message,
            'desktop_visible' => $settings->desktop_visible,
            'mobile_visible' => $settings->mobile_visible,
            'allowed_pages' => $settings->allowed_pages,
            'excluded_pages' => $settings->excluded_pages,
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
                            ->label('Save WhatsApp Settings')
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
            Section::make('Visibility')
                ->description('The button never renders unless it\'s enabled and a number is set.')
                ->schema([
                    Grid::make(3)->schema([
                        Toggle::make('enabled')
                            ->label('Enabled')
                            ->helperText('Master switch for the floating button.'),
                        Toggle::make('desktop_visible')->label('Show on Desktop'),
                        Toggle::make('mobile_visible')->label('Show on Mobile'),
                    ]),
                    TextInput::make('number')
                        ->label('WhatsApp Number')
                        ->helperText('Digits only, with country code and no leading +, spaces, or dashes — e.g. 919876543210.')
                        ->maxLength(20),
                ]),

            Section::make('Message')
                ->description('Pre-filled text the visitor\'s WhatsApp draft opens with.')
                ->schema([
                    Textarea::make('default_message')
                        ->label('Default Message')
                        ->required()
                        ->maxLength(1000)
                        ->rows(2),
                ]),

            Section::make('Page Rules')
                ->description('Optional. Wildcard path patterns (e.g. "about-us", "blog/*") — leave both empty to show the button on every eligible page.')
                ->schema([
                    Grid::make(2)->schema([
                        TagsInput::make('allowed_pages')
                            ->label('Allowed Pages')
                            ->helperText('If set, the button only appears on paths matching one of these patterns.')
                            ->placeholder('Add a path pattern'),
                        TagsInput::make('excluded_pages')
                            ->label('Excluded Pages')
                            ->helperText('Paths matching one of these patterns never show the button, even if allowed above.')
                            ->placeholder('Add a path pattern'),
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

        $saved = $this->saveSettingsWithAudit(WhatsAppSettings::class, 'settings', function (WhatsAppSettings $settings) use ($data): void {
            $settings->enabled = (bool) ($data['enabled'] ?? false);
            $settings->number = (string) ($data['number'] ?? '');
            $settings->default_message = (string) $data['default_message'];
            $settings->desktop_visible = (bool) ($data['desktop_visible'] ?? false);
            $settings->mobile_visible = (bool) ($data['mobile_visible'] ?? false);
            $settings->allowed_pages = array_values((array) ($data['allowed_pages'] ?? []));
            $settings->excluded_pages = array_values((array) ($data['excluded_pages'] ?? []));
        });

        if (! $saved) {
            return;
        }

        Notification::make()
            ->title('WhatsApp settings saved')
            ->success()
            ->send();
    }
}
