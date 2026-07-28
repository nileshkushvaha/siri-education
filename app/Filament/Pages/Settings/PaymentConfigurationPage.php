<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Filament\Support\Presentation\BackAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form as FormComponent;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class PaymentConfigurationPage extends PaymentSettingsPage
{
    use HasCentralizedNavigation;
    use HasSettingsSectionBreadcrumb;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog8Tooth;

    protected static ?string $navigationLabel = 'Payment Configuration';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'payment-settings/configuration';

    public static function getLabel(): string
    {
        return 'Payment Configuration';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Payment Configuration Settings';
    }

    public function getSubheading(): ?string
    {
        return 'Control invoice numbering, tax, currency and payment defaults.';
    }

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::make(
                PaymentSettingsNavigationPage::canAccess() ? PaymentSettingsNavigationPage::getUrl() : null,
                'Back to Payment Settings',
            ),
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
                            ->label('Save Configuration')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->paymentConfigurationSchema());
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        if (! $this->saveConfigurationSettings($data)) {
            return;
        }

        Notification::make()
            ->title('Payment configuration saved')
            ->success()
            ->send();
    }
}
