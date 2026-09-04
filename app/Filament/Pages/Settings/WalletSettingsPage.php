<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Settings\FeatureSettings;
use App\Wallet\Services\WalletCurrencyLimitService;
use BackedEnum;
use Filament\Actions\Action;
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
use InvalidArgumentException;

/**
 * Settings → Wallet. Every wallet rule is an amount of money, so every
 * rule is per currency (this application has no exchange rate). The page
 * is a thin form over WalletCurrencyLimitService; nothing here touches
 * the currencies table directly.
 */
class WalletSettingsPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use HasSettingsSectionBreadcrumb;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $navigationLabel = 'Wallet';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'settings/wallet';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'Wallet Settings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Wallet Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $enabled = app(FeatureSettings::class)->wallet_enabled;

        return 'Recharge limits and the low-balance alert, per currency. Leave a field blank to switch that rule off for the currency.'
            .($enabled ? '' : ' The Wallet module is currently switched off under Platform Foundation → Feature Flags.');
    }

    public function mount(): void
    {
        $this->form->fill(['limits' => app(WalletCurrencyLimitService::class)->current()]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];

        foreach (app(WalletCurrencyLimitService::class)->current() as $id => $row) {
            $sections[] = Section::make("{$row['code']} — {$row['name']}")
                ->description(sprintf('Amounts in %s, up to %d decimal place%s.', $row['code'], $row['minor_units'], $row['minor_units'] === 1 ? '' : 's'))
                ->columnSpanFull()
                ->schema([
                    Grid::make(4)->schema([
                        $this->amountInput("limits.{$id}.minimum_recharge_minor", 'Minimum recharge', 'Smallest recharge a student may make. Blank = any positive amount.'),
                        $this->amountInput("limits.{$id}.maximum_recharge_minor", 'Maximum recharge', 'Largest single recharge. Blank = no cap.'),
                        $this->amountInput("limits.{$id}.recharge_multiple_minor", 'Recharge step', 'Amount must be a whole multiple of this, e.g. 10 allows 500 and 510 but not 505.'),
                        $this->amountInput("limits.{$id}.low_balance_threshold_minor", 'Low-balance alert', 'Students see a low-balance warning once their available balance drops below this.'),
                    ]),
                ]);
        }

        return $schema
            ->components([
                FormComponent::make([
                    ...$sections,
                    ActionsComponent::make([
                        Action::make('save')
                            ->label('Save Wallet Settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])->columnSpanFull(),
                ])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->columns(1),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        try {
            $changed = app(WalletCurrencyLimitService::class)->update(auth()->user(), $data['limits'] ?? []);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Wallet settings not saved')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title($changed === 0 ? 'No changes to save' : 'Wallet settings saved')
            ->body($changed === 0 ? null : "{$changed} ".($changed === 1 ? 'currency' : 'currencies').' updated.')
            ->success()
            ->send();
    }

    private function amountInput(string $name, string $label, string $help): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->nullable()
            ->numeric()
            ->minValue(0)
            ->helperText($help);
    }
}
