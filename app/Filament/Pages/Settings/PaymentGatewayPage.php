<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Filament\Support\Presentation\BackAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form as FormComponent;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

class PaymentGatewayPage extends PaymentSettingsPage
{
    use HasCentralizedNavigation;
    use HasSettingsSectionBreadcrumb;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $navigationLabel = 'Payment Gateways';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'payment-settings/gateways';

    public static function getLabel(): string
    {
        return 'Payment Gateways';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Payment Gateway Settings';
    }

    public function getSubheading(): ?string
    {
        return 'Configure and secure online payment gateway credentials.';
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
                            ->label('Save Gateway Settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                        Action::make('validate_credentials')
                            ->label('Validate Credentials')
                            ->icon(Heroicon::OutlinedCheckBadge)
                            ->color('warning')
                            ->form([
                                Select::make('gateway')
                                    ->label('Gateway')
                                    ->options($this->gatewayOptions())
                                    ->required()
                                    ->native(false),
                            ])
                            ->action(fn (array $data) => $this->validateGatewayCredentials($data['gateway'])),
                        Action::make('test_connection')
                            ->label('Test Connection')
                            ->icon(Heroicon::OutlinedSignal)
                            ->color('info')
                            ->form([
                                Select::make('gateway')
                                    ->label('Gateway')
                                    ->options($this->gatewayOptions())
                                    ->required()
                                    ->native(false),
                            ])
                            ->action(fn (array $data) => $this->testGatewayConnection($data['gateway'])),
                        Action::make('generate_webhook_secret')
                            ->label('Generate Webhook Secret')
                            ->icon(Heroicon::OutlinedKey)
                            ->color('gray')
                            ->form([
                                Select::make('gateway')
                                    ->label('Gateway')
                                    ->options($this->gatewayOptions())
                                    ->required()
                                    ->native(false),
                            ])
                            ->action(function (array $data): void {
                                $field = "{$data['gateway']}_webhook_secret";
                                $this->data[$field] = Str::random(48);

                                Notification::make()
                                    ->title('Webhook secret generated')
                                    ->body('Save settings to persist the generated secret.')
                                    ->success()
                                    ->send();
                            }),
                        Action::make('copy_webhook_url')
                            ->label('Copy Webhook URL')
                            ->icon(Heroicon::OutlinedClipboardDocument)
                            ->color('primary')
                            ->form([
                                Select::make('gateway')
                                    ->label('Gateway')
                                    ->options($this->gatewayOptions())
                                    ->required()
                                    ->native(false),
                            ])
                            ->action(fn (array $data) => $this->copyWebhookUrl($data['gateway'])),
                        Action::make('reset_credentials')
                            ->label('Reset Stored Secrets')
                            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                            ->color('danger')
                            ->requiresConfirmation()
                            ->modalDescription('This clears all stored encrypted credentials for every gateway.')
                            ->action(fn () => $this->resetGatewayCredentials()),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->gatewaySchema());
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        if (! $this->saveGatewaySettings($data)) {
            return;
        }

        Notification::make()
            ->title('Payment gateways saved')
            ->success()
            ->send();
    }
}
