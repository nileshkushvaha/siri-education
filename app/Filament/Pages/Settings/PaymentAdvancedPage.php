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

class PaymentAdvancedPage extends PaymentSettingsPage
{
    use HasCentralizedNavigation;
    use HasSettingsSectionBreadcrumb;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $navigationLabel = 'Advanced';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'payment-settings/advanced';

    public static function getLabel(): string
    {
        return 'Advanced';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Advanced Payment Settings';
    }

    public function getSubheading(): ?string
    {
        return 'Configure webhook retries, queue processing, and payment logging.';
    }

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::make(
                PaymentGatewayPage::canAccess() ? PaymentGatewayPage::getUrl() : null,
                'Back to Payment Gateway Settings',
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
                            ->label('Save Advanced Settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->advancedSchema());
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        if (! $this->saveAdvancedSettings($data)) {
            return;
        }

        Notification::make()
            ->title('Advanced payment settings saved')
            ->success()
            ->send();
    }
}
