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

class PaymentBankAccountPage extends PaymentSettingsPage
{
    use HasCentralizedNavigation;
    use HasSettingsSectionBreadcrumb;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Bank Account';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'payment-settings/bank-account';

    public static function getLabel(): string
    {
        return 'Bank Account';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Bank Account Settings';
    }

    public function getSubheading(): ?string
    {
        return 'Manage offline payment and bank transfer details.';
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
                            ->label('Save Bank Settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->bankAccountSchema());
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        if (! $this->saveBankSettings($data)) {
            return;
        }

        Notification::make()
            ->title('Bank account settings saved')
            ->success()
            ->send();
    }
}
