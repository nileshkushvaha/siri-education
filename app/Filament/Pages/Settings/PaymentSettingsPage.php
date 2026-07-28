<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Booking\Services\PaymentGatewayConfigurationService;
use App\Settings\BankSettings;
use App\Settings\PaymentAdvancedSettings;
use App\Settings\PaymentConfigurationSettings;
use App\Settings\PaymentGatewaySettings;
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
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form as FormComponent;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

abstract class PaymentSettingsPage extends Page
{
    use HasSettingsAccess;
    use LogsSettingsUpdates;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = null;

    protected static ?string $slug = null;

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public int $activePaymentTab = 1;

    public static function getLabel(): string
    {
        return 'Payment Settings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Payment Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Configure bank transfers, gateways, payment rules, and advanced webhook/queue behaviour.';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function mount(): void
    {
        $bank = app(BankSettings::class);
        $gateways = app(PaymentGatewaySettings::class);
        $configuration = app(PaymentConfigurationSettings::class);
        $advanced = app(PaymentAdvancedSettings::class);

        $section = (string) request()->query('section', 'bank');
        $this->activePaymentTab = match ($section) {
            'bank' => 1,
            'gateways' => 2,
            'configuration' => 3,
            'advanced' => 4,
            default => 1,
        };

        $this->form->fill([
            // bank
            'enable_offline_payment' => $bank->enable_offline_payment,
            'account_holder_name' => $bank->account_holder_name,
            'bank_name' => $bank->bank_name,
            'branch_name' => $bank->branch_name,
            'account_number' => $bank->account_number,
            'account_number_confirm' => null,
            'ifsc_code' => $bank->ifsc_code,
            'swift_code' => $bank->swift_code,
            'iban' => $bank->iban,
            'account_type' => $bank->account_type,
            'upi_id' => $bank->upi_id,
            'qr_code_image' => $bank->qr_code_image,
            'payment_instructions' => $bank->payment_instructions,
            'display_on_invoice' => $bank->display_on_invoice,
            'display_on_payment_page' => $bank->display_on_payment_page,

            // gateways (never prefill secrets)
            'stripe_enabled' => $gateways->stripe_enabled,
            'stripe_sandbox_mode' => $gateways->stripe_sandbox_mode,
            'stripe_publishable_key' => $gateways->stripe_publishable_key,
            'stripe_secret_key' => null,
            'stripe_webhook_secret' => null,
            'stripe_success_url' => $gateways->stripe_success_url ?? url('/payments/stripe/success'),
            'stripe_failure_url' => $gateways->stripe_failure_url ?? url('/payments/stripe/failure'),
            // This must be the real settlement route
            // (api/webhooks/bookings/payments/{provider}) — the generic
            // api/webhooks/payments/generic/{gateway} path only logs/audits
            // and never settles a booking (see PaymentWebhookProcessor).
            // Stripe/Razorpay are the only gateways with a registered
            // PaymentProviderInterface adapter, so only their defaults
            // change here; paypal/cashfree/payu/phonepe/manual have no
            // adapter at all and genuinely have nowhere else to point —
            // their defaults below intentionally still use the generic path.
            'stripe_webhook_url' => $gateways->stripe_webhook_url ?? url('/api/webhooks/bookings/payments/stripe'),

            'razorpay_enabled' => $gateways->razorpay_enabled,
            'razorpay_sandbox_mode' => $gateways->razorpay_sandbox_mode,
            'razorpay_key_id' => $gateways->razorpay_key_id,
            'razorpay_key_secret' => null,
            'razorpay_webhook_secret' => null,
            'razorpay_success_url' => $gateways->razorpay_success_url ?? url('/payments/razorpay/success'),
            'razorpay_failure_url' => $gateways->razorpay_failure_url ?? url('/payments/razorpay/failure'),
            'razorpay_webhook_url' => $gateways->razorpay_webhook_url ?? url('/api/webhooks/bookings/payments/razorpay'),

            'paypal_enabled' => $gateways->paypal_enabled,
            'paypal_mode' => $gateways->paypal_mode,
            'paypal_client_id' => $gateways->paypal_client_id,
            'paypal_client_secret' => null,
            'paypal_webhook_secret' => null,
            'paypal_success_url' => $gateways->paypal_success_url ?? url('/payments/paypal/success'),
            'paypal_failure_url' => $gateways->paypal_failure_url ?? url('/payments/paypal/failure'),
            'paypal_webhook_url' => $gateways->paypal_webhook_url ?? url('/api/webhooks/payments/generic/paypal'),

            'cashfree_enabled' => $gateways->cashfree_enabled,
            'cashfree_environment' => $gateways->cashfree_environment,
            'cashfree_app_id' => $gateways->cashfree_app_id,
            'cashfree_secret_key' => null,
            'cashfree_webhook_secret' => null,
            'cashfree_success_url' => $gateways->cashfree_success_url ?? url('/payments/cashfree/success'),
            'cashfree_failure_url' => $gateways->cashfree_failure_url ?? url('/payments/cashfree/failure'),
            'cashfree_webhook_url' => $gateways->cashfree_webhook_url ?? url('/api/webhooks/payments/generic/cashfree'),

            'payu_enabled' => $gateways->payu_enabled,
            'payu_sandbox_mode' => $gateways->payu_sandbox_mode,
            'payu_merchant_id' => $gateways->payu_merchant_id,
            'payu_public_key' => $gateways->payu_public_key,
            'payu_private_key' => null,
            'payu_webhook_secret' => null,
            'payu_success_url' => $gateways->payu_success_url ?? url('/payments/payu/success'),
            'payu_failure_url' => $gateways->payu_failure_url ?? url('/payments/payu/failure'),
            'payu_webhook_url' => $gateways->payu_webhook_url ?? url('/api/webhooks/payments/generic/payu'),

            'phonepe_enabled' => $gateways->phonepe_enabled,
            'phonepe_sandbox_mode' => $gateways->phonepe_sandbox_mode,
            'phonepe_merchant_id' => $gateways->phonepe_merchant_id,
            'phonepe_salt_key' => null,
            'phonepe_salt_index' => $gateways->phonepe_salt_index,
            'phonepe_webhook_secret' => null,
            'phonepe_success_url' => $gateways->phonepe_success_url ?? url('/payments/phonepe/success'),
            'phonepe_failure_url' => $gateways->phonepe_failure_url ?? url('/payments/phonepe/failure'),
            'phonepe_webhook_url' => $gateways->phonepe_webhook_url ?? url('/api/webhooks/payments/generic/phonepe'),

            'manual_enabled' => $gateways->manual_enabled,
            'manual_payment_instructions' => $gateways->manual_payment_instructions,

            // payment configuration
            'currency' => $configuration->currency,
            'currency_symbol' => $configuration->currency_symbol,
            'decimal_precision' => $configuration->decimal_precision,
            'default_tax_percent' => $configuration->default_tax_percent,
            'invoice_prefix' => $configuration->invoice_prefix,
            'invoice_number_length' => $configuration->invoice_number_length,
            'payment_due_days' => $configuration->payment_due_days,
            'allow_partial_payment' => $configuration->allow_partial_payment,
            'auto_generate_invoice' => $configuration->auto_generate_invoice,
            'auto_capture_payment' => $configuration->auto_capture_payment,
            'refund_enabled' => $configuration->refund_enabled,

            // advanced
            'webhook_timeout' => $advanced->webhook_timeout,
            'retry_failed_payments' => $advanced->retry_failed_payments,
            'queue_payment_events' => $advanced->queue_payment_events,
            'payment_logging' => $advanced->payment_logging,
            'enable_audit_log' => $advanced->enable_audit_log,
            'max_retry_count' => $advanced->max_retry_count,
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
                            ->label('Save Payment Settings')
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
                            ->label('Check Connection Readiness')
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
                        Action::make('mark_production_reviewed')
                            ->label('Mark Production Checklist Reviewed')
                            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                            ->color('success')
                            ->requiresConfirmation()
                            ->modalDescription('Confirms an administrator has completed the production readiness checklist for the currently enabled gateways before enabling them for real traffic.')
                            ->action(fn () => $this->markProductionChecklistReviewed()),
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
            Tabs::make('Payment Settings')
                ->activeTab(fn (): int => $this->activePaymentTab)
                ->persistTabInQueryString()
                ->vertical(false)
                ->tabs([
                    Tab::make('Bank Account')
                        ->icon(Heroicon::OutlinedBuildingLibrary)
                        ->schema($this->bankAccountSchema()),
                    Tab::make('Payment Gateways')
                        ->icon(Heroicon::OutlinedCreditCard)
                        ->schema($this->gatewaySchema()),
                    Tab::make('Payment Configuration')
                        ->icon(Heroicon::OutlinedCog8Tooth)
                        ->schema($this->paymentConfigurationSchema()),
                    Tab::make('Advanced')
                        ->icon(Heroicon::OutlinedWrenchScrewdriver)
                        ->schema($this->advancedSchema()),
                ]),
        ]);
    }

    /**
     * @return array<Component>
     */
    protected function bankAccountSchema(): array
    {
        return [
            Section::make('Offline Payment (Bank / NEFT / RTGS / IMPS / UPI)')
                ->description('Configure your bank transfer details for offline payments.')
                ->icon(Heroicon::OutlinedBanknotes)
                ->schema([
                    Toggle::make('enable_offline_payment')
                        ->label('Enable Offline Payment')
                        ->live(),
                    Grid::make(2)->schema([
                        TextInput::make('account_holder_name')
                            ->label('Account Holder Name')
                            ->required(fn (Get $get): bool => (bool) $get('enable_offline_payment'))
                            ->maxLength(150),
                        TextInput::make('bank_name')
                            ->label('Bank Name')
                            ->required(fn (Get $get): bool => (bool) $get('enable_offline_payment'))
                            ->maxLength(150),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('branch_name')
                            ->label('Branch Name')
                            ->maxLength(150),
                        Select::make('account_type')
                            ->label('Account Type')
                            ->options([
                                'current' => 'Current',
                                'savings' => 'Savings',
                            ])
                            ->required()
                            ->native(false),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('account_number')
                            ->label('Account Number')
                            ->required(fn (Get $get): bool => (bool) $get('enable_offline_payment'))
                            ->regex('/^[0-9]{6,24}$/')
                            ->maxLength(24),
                        TextInput::make('account_number_confirm')
                            ->label('Confirm Account Number')
                            ->required(fn (Get $get): bool => (bool) $get('enable_offline_payment'))
                            ->same('account_number')
                            ->dehydrated(false),
                    ]),
                    Grid::make(3)->schema([
                        TextInput::make('ifsc_code')
                            ->label('IFSC Code')
                            ->regex('/^[A-Z]{4}0[A-Z0-9]{6}$/')
                            ->required(fn (Get $get): bool => (bool) $get('enable_offline_payment'))
                            ->maxLength(11),
                        TextInput::make('swift_code')
                            ->label('SWIFT Code')
                            ->maxLength(20),
                        TextInput::make('iban')
                            ->label('IBAN (Optional)')
                            ->maxLength(34),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('upi_id')
                            ->label('UPI ID')
                            ->maxLength(120)
                            ->placeholder('name@bank'),
                        FileUpload::make('qr_code_image')
                            ->label('QR Code Image')
                            ->image()
                            ->disk('public')
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml'])
                            ->maxSize(2048)
                            ->directory('settings/payment'),
                    ]),
                    Textarea::make('payment_instructions')
                        ->label('Payment Instructions')
                        ->rows(3)
                        ->helperText('These instructions are displayed on invoice/payment pages.')
                        ->maxLength(2000),
                    Grid::make(2)->schema([
                        Toggle::make('display_on_invoice')->label('Display On Invoice'),
                        Toggle::make('display_on_payment_page')->label('Display On Payment Page'),
                    ]),
                ]),
        ];
    }

    /**
     * @return array<Component>
     */
    protected function gatewaySchema(): array
    {
        return [
            Tabs::make('Gateway Cards')
                ->activeTab(1)
                ->vertical(false)
                ->persistTabInQueryString('gateway_tab')
                ->tabs([
                    $this->stripeTab(),
                    $this->razorpayTab(),
                    $this->paypalTab(),
                    $this->cashfreeTab(),
                    $this->payuTab(),
                    $this->phonepeTab(),
                    $this->manualPaymentTab(),
                ]),
        ];
    }

    protected function stripeTab(): Tab
    {
        return Tab::make('Stripe')
            ->icon(Heroicon::OutlinedCreditCard)
            ->badge(fn (): string => $this->configStatusBadge('stripe'))
            ->badgeColor(fn (): string => $this->configStatusColor('stripe'))
            ->schema([
                Section::make('Stripe')
                    ->description('Stripe • Publishable / Secret keys')
                    ->schema([
                        $this->gatewaySwitches('stripe_enabled', 'stripe_sandbox_mode'),
                        Grid::make(2)->schema([
                            TextInput::make('stripe_publishable_key')->label('Publishable Key')->maxLength(255),
                            TextInput::make('stripe_secret_key')
                                ->label('Secret Key')
                                ->password()
                                ->revealable()
                                ->maxLength(255)
                                ->helperText('Stored encrypted. Leave blank to keep existing.'),
                        ]),
                        TextInput::make('stripe_webhook_secret')
                            ->label('Webhook Secret')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText('Stored encrypted.'),
                        $this->gatewayUrls('stripe'),
                    ]),
            ]);
    }

    protected function razorpayTab(): Tab
    {
        return Tab::make('Razorpay')
            ->icon(Heroicon::OutlinedCreditCard)
            ->badge(fn (): string => $this->configStatusBadge('razorpay'))
            ->badgeColor(fn (): string => $this->configStatusColor('razorpay'))
            ->schema([
                Section::make('Razorpay')
                    ->schema([
                        $this->gatewaySwitches('razorpay_enabled', 'razorpay_sandbox_mode'),
                        Grid::make(2)->schema([
                            TextInput::make('razorpay_key_id')->label('Key ID')->maxLength(255),
                            TextInput::make('razorpay_key_secret')
                                ->label('Key Secret')
                                ->password()
                                ->revealable()
                                ->maxLength(255)
                                ->helperText('Stored encrypted. Leave blank to keep existing.'),
                        ]),
                        TextInput::make('razorpay_webhook_secret')
                            ->label('Webhook Secret')
                            ->password()
                            ->revealable()
                            ->maxLength(255),
                        $this->gatewayUrls('razorpay'),
                    ]),
            ]);
    }

    protected function paypalTab(): Tab
    {
        return Tab::make('PayPal')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->badge(fn (): string => $this->enabledBadge('paypal_enabled'))
            ->schema([
                Section::make('PayPal')
                    ->schema([
                        Toggle::make('paypal_enabled')->label('Enable Gateway')->live(),
                        Select::make('paypal_mode')
                            ->label('Mode')
                            ->options(['sandbox' => 'Sandbox', 'live' => 'Live'])
                            ->native(false)
                            ->required(),
                        Grid::make(2)->schema([
                            TextInput::make('paypal_client_id')->label('Client ID')->maxLength(255),
                            TextInput::make('paypal_client_secret')
                                ->label('Client Secret')
                                ->password()
                                ->revealable()
                                ->maxLength(255)
                                ->helperText('Stored encrypted.'),
                        ]),
                        TextInput::make('paypal_webhook_secret')
                            ->label('Webhook Secret')
                            ->password()
                            ->revealable()
                            ->maxLength(255),
                        $this->gatewayUrls('paypal'),
                    ]),
            ]);
    }

    protected function cashfreeTab(): Tab
    {
        return Tab::make('Cashfree')
            ->icon(Heroicon::OutlinedBanknotes)
            ->badge(fn (): string => $this->enabledBadge('cashfree_enabled'))
            ->schema([
                Section::make('Cashfree')
                    ->schema([
                        Toggle::make('cashfree_enabled')->label('Enable Gateway')->live(),
                        Select::make('cashfree_environment')
                            ->label('Environment')
                            ->options(['sandbox' => 'Sandbox', 'production' => 'Production'])
                            ->native(false)
                            ->required(),
                        Grid::make(2)->schema([
                            TextInput::make('cashfree_app_id')->label('App ID')->maxLength(255),
                            TextInput::make('cashfree_secret_key')
                                ->label('Secret Key')
                                ->password()
                                ->revealable()
                                ->maxLength(255)
                                ->helperText('Stored encrypted.'),
                        ]),
                        TextInput::make('cashfree_webhook_secret')
                            ->label('Webhook Secret')
                            ->password()
                            ->revealable()
                            ->maxLength(255),
                        $this->gatewayUrls('cashfree'),
                    ]),
            ]);
    }

    protected function payuTab(): Tab
    {
        return Tab::make('PayU')
            ->icon(Heroicon::OutlinedWallet)
            ->badge(fn (): string => $this->enabledBadge('payu_enabled'))
            ->schema([
                Section::make('PayU')
                    ->schema([
                        $this->gatewaySwitches('payu_enabled', 'payu_sandbox_mode'),
                        Grid::make(2)->schema([
                            TextInput::make('payu_merchant_id')->label('Merchant ID')->maxLength(255),
                            TextInput::make('payu_public_key')->label('Public Key')->maxLength(255),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('payu_private_key')
                                ->label('Private Key')
                                ->password()
                                ->revealable()
                                ->maxLength(255)
                                ->helperText('Stored encrypted.'),
                            TextInput::make('payu_webhook_secret')
                                ->label('Webhook Secret')
                                ->password()
                                ->revealable()
                                ->maxLength(255),
                        ]),
                        $this->gatewayUrls('payu'),
                    ]),
            ]);
    }

    protected function phonepeTab(): Tab
    {
        return Tab::make('PhonePe')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->badge(fn (): string => $this->enabledBadge('phonepe_enabled'))
            ->schema([
                Section::make('PhonePe')
                    ->schema([
                        $this->gatewaySwitches('phonepe_enabled', 'phonepe_sandbox_mode'),
                        Grid::make(2)->schema([
                            TextInput::make('phonepe_merchant_id')->label('Merchant ID')->maxLength(255),
                            TextInput::make('phonepe_salt_index')->label('Salt Index')->maxLength(20),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('phonepe_salt_key')
                                ->label('Salt Key')
                                ->password()
                                ->revealable()
                                ->maxLength(255)
                                ->helperText('Stored encrypted.'),
                            TextInput::make('phonepe_webhook_secret')
                                ->label('Webhook Secret')
                                ->password()
                                ->revealable()
                                ->maxLength(255),
                        ]),
                        $this->gatewayUrls('phonepe'),
                    ]),
            ]);
    }

    protected function manualPaymentTab(): Tab
    {
        return Tab::make('Manual Payment')
            ->icon(Heroicon::OutlinedDocumentText)
            ->badge(fn (): string => $this->enabledBadge('manual_enabled'))
            ->schema([
                Section::make('Manual Payment')
                    ->description('Fallback manual payment instructions.')
                    ->schema([
                        Toggle::make('manual_enabled')->label('Enable Gateway'),
                        Textarea::make('manual_payment_instructions')
                            ->label('Payment Instructions')
                            ->rows(4)
                            ->maxLength(2000),
                    ]),
            ]);
    }

    protected function gatewaySwitches(string $enabledField, string $sandboxField): Grid
    {
        return Grid::make(2)->schema([
            Toggle::make($enabledField)->label('Enable Gateway')->live(),
            Toggle::make($sandboxField)->label('Sandbox Mode'),
        ]);
    }

    protected function gatewayUrls(string $prefix): Grid
    {
        return Grid::make(3)->schema([
            TextInput::make("{$prefix}_success_url")
                ->label('Success URL')
                ->url()
                ->maxLength(255),
            TextInput::make("{$prefix}_failure_url")
                ->label('Failure URL')
                ->url()
                ->maxLength(255),
            TextInput::make("{$prefix}_webhook_url")
                ->label('Webhook URL')
                ->url()
                ->maxLength(255)
                ->readOnly(),
        ]);
    }

    /**
     * @return array<Component>
     */
    protected function paymentConfigurationSchema(): array
    {
        return [
            Section::make('Payment Configuration')
                ->description('Invoice, currency, tax and payment behaviour.')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('currency')
                            ->label('Currency')
                            ->options([
                                'INR' => 'INR',
                                'USD' => 'USD',
                                'EUR' => 'EUR',
                                'GBP' => 'GBP',
                                'AED' => 'AED',
                            ])
                            ->required()
                            ->native(false),
                        TextInput::make('currency_symbol')
                            ->label('Currency Symbol')
                            ->required()
                            ->maxLength(6),
                        Select::make('decimal_precision')
                            ->label('Decimal Precision')
                            ->options([0 => '0', 1 => '1', 2 => '2', 3 => '3'])
                            ->required()
                            ->native(false),
                    ]),
                    Grid::make(3)->schema([
                        TextInput::make('default_tax_percent')
                            ->label('Default Tax %')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(100),
                        TextInput::make('invoice_prefix')
                            ->label('Invoice Prefix')
                            ->required()
                            ->maxLength(20),
                        TextInput::make('invoice_number_length')
                            ->label('Invoice Number Length')
                            ->numeric()
                            ->required()
                            ->minValue(4)
                            ->maxValue(20),
                    ]),
                    Grid::make(3)->schema([
                        TextInput::make('payment_due_days')
                            ->label('Payment Due Days')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(365),
                        Toggle::make('allow_partial_payment')->label('Allow Partial Payment'),
                        Toggle::make('auto_generate_invoice')->label('Auto Generate Invoice'),
                    ]),
                    Grid::make(2)->schema([
                        Toggle::make('auto_capture_payment')->label('Auto Capture Payment'),
                        Toggle::make('refund_enabled')->label('Refund Enabled'),
                    ]),
                ]),
        ];
    }

    /**
     * @return array<Component>
     */
    protected function advancedSchema(): array
    {
        return [
            Section::make('Advanced')
                ->description('Webhook processing, retries, queue and logging.')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('webhook_timeout')
                            ->label('Webhook Timeout (sec)')
                            ->numeric()
                            ->required()
                            ->minValue(5)
                            ->maxValue(300),
                        TextInput::make('max_retry_count')
                            ->label('Maximum Retry Count')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(20),
                        Toggle::make('retry_failed_payments')->label('Retry Failed Payments'),
                    ]),
                    Grid::make(3)->schema([
                        Toggle::make('queue_payment_events')->label('Queue Payment Events'),
                        Toggle::make('payment_logging')->label('Payment Logging'),
                        Toggle::make('enable_audit_log')->label('Enable Audit Log'),
                    ]),
                ]),
        ];
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        $bankOk = $this->saveBankSettings($data);
        $gatewayOk = $this->saveGatewaySettings($data);
        $configOk = $this->saveConfigurationSettings($data);
        $advancedOk = $this->saveAdvancedSettings($data);

        if (! $bankOk || ! $gatewayOk || ! $configOk || ! $advancedOk) {
            // A failure notification was already shown by
            // saveSettingsWithAudit() for whichever group failed.
            return;
        }

        Notification::make()
            ->title('Payment settings saved')
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveBankSettings(array $data): bool
    {
        return $this->saveSettingsWithAudit(BankSettings::class, 'settings', function (BankSettings $bank) use ($data): void {
            $bank->enable_offline_payment = (bool) ($data['enable_offline_payment'] ?? false);
            $bank->account_holder_name = $data['account_holder_name'] ?? null;
            $bank->bank_name = $data['bank_name'] ?? null;
            $bank->branch_name = $data['branch_name'] ?? null;
            $bank->account_number = $data['account_number'] ?? null;
            $bank->ifsc_code = isset($data['ifsc_code']) ? strtoupper((string) $data['ifsc_code']) : null;
            $bank->swift_code = isset($data['swift_code']) ? strtoupper((string) $data['swift_code']) : null;
            $bank->iban = isset($data['iban']) ? strtoupper((string) $data['iban']) : null;
            $bank->account_type = $data['account_type'] ?? 'current';
            $bank->upi_id = $data['upi_id'] ?? null;
            $bank->qr_code_image = $data['qr_code_image'] ?? $bank->qr_code_image;
            $bank->payment_instructions = $data['payment_instructions'] ?? null;
            $bank->display_on_invoice = (bool) ($data['display_on_invoice'] ?? false);
            $bank->display_on_payment_page = (bool) ($data['display_on_payment_page'] ?? false);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveGatewaySettings(array $data): bool
    {
        return $this->saveSettingsWithAudit(PaymentGatewaySettings::class, 'settings', function (PaymentGatewaySettings $settings) use ($data): void {
            foreach ($this->gatewayPrefixes() as $prefix) {
                $enabledField = "{$prefix}_enabled";
                $settings->{$enabledField} = (bool) ($data[$enabledField] ?? false);
            }

            $settings->stripe_sandbox_mode = (bool) ($data['stripe_sandbox_mode'] ?? true);
            $settings->stripe_publishable_key = $data['stripe_publishable_key'] ?? null;
            $settings->stripe_success_url = $data['stripe_success_url'] ?? null;
            $settings->stripe_failure_url = $data['stripe_failure_url'] ?? null;
            $settings->stripe_webhook_url = $data['stripe_webhook_url'] ?? null;

            $settings->razorpay_sandbox_mode = (bool) ($data['razorpay_sandbox_mode'] ?? true);
            $settings->razorpay_key_id = $data['razorpay_key_id'] ?? null;
            $settings->razorpay_success_url = $data['razorpay_success_url'] ?? null;
            $settings->razorpay_failure_url = $data['razorpay_failure_url'] ?? null;
            $settings->razorpay_webhook_url = $data['razorpay_webhook_url'] ?? null;

            $settings->paypal_mode = $data['paypal_mode'] ?? 'sandbox';
            $settings->paypal_client_id = $data['paypal_client_id'] ?? null;
            $settings->paypal_success_url = $data['paypal_success_url'] ?? null;
            $settings->paypal_failure_url = $data['paypal_failure_url'] ?? null;
            $settings->paypal_webhook_url = $data['paypal_webhook_url'] ?? null;

            $settings->cashfree_environment = $data['cashfree_environment'] ?? 'sandbox';
            $settings->cashfree_app_id = $data['cashfree_app_id'] ?? null;
            $settings->cashfree_success_url = $data['cashfree_success_url'] ?? null;
            $settings->cashfree_failure_url = $data['cashfree_failure_url'] ?? null;
            $settings->cashfree_webhook_url = $data['cashfree_webhook_url'] ?? null;

            $settings->payu_sandbox_mode = (bool) ($data['payu_sandbox_mode'] ?? true);
            $settings->payu_merchant_id = $data['payu_merchant_id'] ?? null;
            $settings->payu_public_key = $data['payu_public_key'] ?? null;
            $settings->payu_success_url = $data['payu_success_url'] ?? null;
            $settings->payu_failure_url = $data['payu_failure_url'] ?? null;
            $settings->payu_webhook_url = $data['payu_webhook_url'] ?? null;

            $settings->phonepe_sandbox_mode = (bool) ($data['phonepe_sandbox_mode'] ?? true);
            $settings->phonepe_merchant_id = $data['phonepe_merchant_id'] ?? null;
            $settings->phonepe_salt_index = $data['phonepe_salt_index'] ?? null;
            $settings->phonepe_success_url = $data['phonepe_success_url'] ?? null;
            $settings->phonepe_failure_url = $data['phonepe_failure_url'] ?? null;
            $settings->phonepe_webhook_url = $data['phonepe_webhook_url'] ?? null;

            $settings->manual_payment_instructions = $data['manual_payment_instructions'] ?? null;

            // encrypted secrets
            $this->saveEncryptedField($settings, 'stripe_secret_key', $data['stripe_secret_key'] ?? null);
            $this->saveEncryptedField($settings, 'stripe_webhook_secret', $data['stripe_webhook_secret'] ?? null);
            $this->saveEncryptedField($settings, 'razorpay_key_secret', $data['razorpay_key_secret'] ?? null);
            $this->saveEncryptedField($settings, 'razorpay_webhook_secret', $data['razorpay_webhook_secret'] ?? null);
            $this->saveEncryptedField($settings, 'paypal_client_secret', $data['paypal_client_secret'] ?? null);
            $this->saveEncryptedField($settings, 'paypal_webhook_secret', $data['paypal_webhook_secret'] ?? null);
            $this->saveEncryptedField($settings, 'cashfree_secret_key', $data['cashfree_secret_key'] ?? null);
            $this->saveEncryptedField($settings, 'cashfree_webhook_secret', $data['cashfree_webhook_secret'] ?? null);
            $this->saveEncryptedField($settings, 'payu_private_key', $data['payu_private_key'] ?? null);
            $this->saveEncryptedField($settings, 'payu_webhook_secret', $data['payu_webhook_secret'] ?? null);
            $this->saveEncryptedField($settings, 'phonepe_salt_key', $data['phonepe_salt_key'] ?? null);
            $this->saveEncryptedField($settings, 'phonepe_webhook_secret', $data['phonepe_webhook_secret'] ?? null);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveConfigurationSettings(array $data): bool
    {
        return $this->saveSettingsWithAudit(PaymentConfigurationSettings::class, 'settings', function (PaymentConfigurationSettings $settings) use ($data): void {
            $settings->currency = $data['currency'] ?? 'INR';
            $settings->currency_symbol = $data['currency_symbol'] ?? '₹';
            $settings->decimal_precision = (int) ($data['decimal_precision'] ?? 2);
            $settings->default_tax_percent = (float) ($data['default_tax_percent'] ?? 0);
            $settings->invoice_prefix = $data['invoice_prefix'] ?? 'INV';
            $settings->invoice_number_length = (int) ($data['invoice_number_length'] ?? 8);
            $settings->payment_due_days = (int) ($data['payment_due_days'] ?? 7);
            $settings->allow_partial_payment = (bool) ($data['allow_partial_payment'] ?? false);
            $settings->auto_generate_invoice = (bool) ($data['auto_generate_invoice'] ?? true);
            $settings->auto_capture_payment = (bool) ($data['auto_capture_payment'] ?? true);
            $settings->refund_enabled = (bool) ($data['refund_enabled'] ?? true);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveAdvancedSettings(array $data): bool
    {
        return $this->saveSettingsWithAudit(PaymentAdvancedSettings::class, 'settings', function (PaymentAdvancedSettings $settings) use ($data): void {
            $settings->webhook_timeout = (int) ($data['webhook_timeout'] ?? 30);
            $settings->retry_failed_payments = (bool) ($data['retry_failed_payments'] ?? true);
            $settings->queue_payment_events = (bool) ($data['queue_payment_events'] ?? true);
            $settings->payment_logging = (bool) ($data['payment_logging'] ?? true);
            $settings->enable_audit_log = (bool) ($data['enable_audit_log'] ?? true);
            $settings->max_retry_count = (int) ($data['max_retry_count'] ?? 5);
        });
    }

    protected function saveEncryptedField(PaymentGatewaySettings $settings, string $field, ?string $value): void
    {
        if (filled($value)) {
            $settings->{$field} = Crypt::encryptString($value);
        }
    }

    protected function resetGatewayCredentials(): void
    {
        $ok = $this->saveSettingsWithAudit(PaymentGatewaySettings::class, 'settings', function (PaymentGatewaySettings $settings): void {
            foreach ([
                'stripe_secret_key', 'stripe_webhook_secret',
                'razorpay_key_secret', 'razorpay_webhook_secret',
                'paypal_client_secret', 'paypal_webhook_secret',
                'cashfree_secret_key', 'cashfree_webhook_secret',
                'payu_private_key', 'payu_webhook_secret',
                'phonepe_salt_key', 'phonepe_webhook_secret',
            ] as $secretField) {
                $settings->{$secretField} = null;
            }
        });

        if (! $ok) {
            return;
        }

        Notification::make()
            ->title('Gateway credentials reset')
            ->success()
            ->send();
    }

    /**
     * Records a review timestamp only — never flips payments_enabled or
     * any *_enabled toggle. See docs/architecture/payment-gateway-production-checklist.md
     * for what "reviewed" is supposed to mean before this is clicked.
     */
    protected function markProductionChecklistReviewed(): void
    {
        $now = now();
        $reviewedAt = $now->toIso8601String();

        $ok = $this->saveSettingsWithAudit(PaymentGatewaySettings::class, 'settings', function (PaymentGatewaySettings $settings) use ($reviewedAt): void {
            $settings->production_ready_at = $reviewedAt;
        });

        if (! $ok) {
            return;
        }

        Notification::make()
            ->title('Production checklist marked reviewed')
            ->body('Recorded at '.$now->format('M j, Y g:i A').'. This does not enable any gateway by itself.')
            ->success()
            ->send();
    }

    protected function validateGatewayCredentials(string $gateway): void
    {
        if (in_array($gateway, ['razorpay', 'stripe'], true)) {
            $this->validateAndPersistGatewayReadiness($gateway);

            return;
        }

        $settings = app(PaymentGatewaySettings::class);
        $errors = [];

        foreach ($this->requiredCredentialFields($gateway) as $field) {
            $stateValue = $this->data[$field] ?? null;
            $storedValue = $settings->{$field} ?? null;

            if (blank($stateValue) && blank($storedValue)) {
                $errors[] = $field;
            }
        }

        if ($errors !== []) {
            Notification::make()
                ->title('Missing credentials')
                ->body('Missing fields: '.implode(', ', $errors))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Required fields are filled in')
            ->body(Str::title($gateway).' credentials are present. This does not confirm they are correct.')
            ->success()
            ->send();
    }

    /**
     * Persists only the credential fields for the gateway being
     * validated — never the whole tab's `$this->data` (a prior version
     * of this method called saveGatewaySettings($this->data) directly,
     * which silently persisted *every* gateway's current, possibly
     * unsaved form state — e.g. clicking "Validate Credentials" for
     * Razorpay would also commit an in-progress, not-yet-saved
     * `stripe_enabled` toggle. Reproduced and fixed during the Phase
     * 10.2A audit). Then runs PaymentGatewayConfigurationService's
     * format-only check against what was just persisted — this never
     * calls Razorpay/Stripe over the network.
     */
    protected function validateAndPersistGatewayReadiness(string $gateway): void
    {
        if (! $this->persistCredentialFieldsForValidation($gateway)) {
            return;
        }

        $result = match ($gateway) {
            'razorpay' => app(PaymentGatewayConfigurationService::class)->checkRazorpay(),
            'stripe' => app(PaymentGatewayConfigurationService::class)->checkStripe(),
            default => null,
        };

        if ($result === null) {
            return;
        }

        if (! $result->isReady()) {
            Notification::make()
                ->title(Str::title($gateway).' is not ready')
                ->body(
                    ($result->issues !== [] ? implode(' ', $result->issues).' ' : '')
                    .'Random or placeholder credentials are not valid. Checkout will remain disabled until credentials pass validation.',
                )
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Credentials validated')
            ->body(Str::title($gateway).' credentials look complete and correctly formatted.')
            ->success()
            ->send();
    }

    /**
     * Writes only the enabled flag + credential fields for the given
     * gateway — deliberately narrower than saveGatewaySettings(), which
     * commits every gateway's URLs/mode/enabled flags in one call and
     * is correct for the page's main "Save" button but wrong for a
     * per-gateway validation action (see validateAndPersistGatewayReadiness()).
     */
    protected function persistCredentialFieldsForValidation(string $gateway): bool
    {
        $data = $this->data;

        return $this->saveSettingsWithAudit(PaymentGatewaySettings::class, 'settings', function (PaymentGatewaySettings $settings) use ($gateway, $data): void {
            $settings->{"{$gateway}_enabled"} = (bool) ($data["{$gateway}_enabled"] ?? false);

            match ($gateway) {
                'razorpay' => (function () use ($settings, $data): void {
                    $settings->razorpay_key_id = $data['razorpay_key_id'] ?? null;
                    $this->saveEncryptedField($settings, 'razorpay_key_secret', $data['razorpay_key_secret'] ?? null);
                    $this->saveEncryptedField($settings, 'razorpay_webhook_secret', $data['razorpay_webhook_secret'] ?? null);
                })(),
                'stripe' => (function () use ($settings, $data): void {
                    $settings->stripe_publishable_key = $data['stripe_publishable_key'] ?? null;
                    $this->saveEncryptedField($settings, 'stripe_secret_key', $data['stripe_secret_key'] ?? null);
                    $this->saveEncryptedField($settings, 'stripe_webhook_secret', $data['stripe_webhook_secret'] ?? null);
                })(),
                default => null,
            };
        });
    }

    protected function testGatewayConnection(string $gateway): void
    {
        $enabledField = "{$gateway}_enabled";

        if (! ($this->data[$enabledField] ?? false)) {
            Notification::make()
                ->title('Gateway not enabled')
                ->body('Enable the selected gateway before testing connection.')
                ->warning()
                ->send();

            return;
        }

        $this->validateGatewayCredentials($gateway);

        Notification::make()
            ->title('Credentials checked (not a live test)')
            ->body('This only confirms the credentials are present and correctly formatted — it does not contact the gateway. Credentials have been saved.')
            ->info()
            ->send();
    }

    /**
     * @return array<string>
     */
    protected function requiredCredentialFields(string $gateway): array
    {
        return match ($gateway) {
            'stripe' => ['stripe_publishable_key', 'stripe_secret_key'],
            'razorpay' => ['razorpay_key_id', 'razorpay_key_secret'],
            'paypal' => ['paypal_client_id', 'paypal_client_secret'],
            'cashfree' => ['cashfree_app_id', 'cashfree_secret_key'],
            'payu' => ['payu_merchant_id', 'payu_private_key'],
            'phonepe' => ['phonepe_merchant_id', 'phonepe_salt_key', 'phonepe_salt_index'],
            'manual' => [],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    protected function gatewayOptions(): array
    {
        return [
            'stripe' => 'Stripe',
            'razorpay' => 'Razorpay',
            'paypal' => 'PayPal',
            'cashfree' => 'Cashfree',
            'payu' => 'PayU',
            'phonepe' => 'PhonePe',
            'manual' => 'Manual Payment',
        ];
    }

    /**
     * @return array<string>
     */
    protected function gatewayPrefixes(): array
    {
        return ['stripe', 'razorpay', 'paypal', 'cashfree', 'payu', 'phonepe', 'manual'];
    }

    protected function enabledBadge(string $field): string
    {
        return (bool) ($this->data[$field] ?? false) ? 'Enabled' : 'Disabled';
    }

    /**
     * Reflects PaymentGatewaySettings::{provider}_config_status
     * (set by PaymentGatewayConfigurationService, never hand-edited) —
     * distinct from the enabled/disabled toggle, since a gateway can be
     * enabled with credentials that are missing/random/incomplete.
     */
    protected function configStatusBadge(string $provider): string
    {
        $status = app(PaymentGatewaySettings::class)->{"{$provider}_config_status"} ?? 'not_configured';

        return match ($status) {
            'ready' => 'Ready',
            'incomplete' => 'Incomplete',
            'invalid' => 'Invalid credentials',
            default => 'Not configured',
        };
    }

    protected function configStatusColor(string $provider): string
    {
        $status = app(PaymentGatewaySettings::class)->{"{$provider}_config_status"} ?? 'not_configured';

        return match ($status) {
            'ready' => 'success',
            'incomplete' => 'warning',
            'invalid' => 'danger',
            default => 'gray',
        };
    }

    protected function copyWebhookUrl(string $gateway): void
    {
        $field = "{$gateway}_webhook_url";
        $url = (string) ($this->data[$field] ?? '');

        if (blank($url)) {
            Notification::make()
                ->title('Webhook URL is empty')
                ->warning()
                ->send();

            return;
        }

        // Livewire v3 supports dispatching inline browser JavaScript.
        if (method_exists($this, 'js')) {
            $encodedUrl = json_encode($url);
            $this->js("window.navigator.clipboard.writeText({$encodedUrl});");
        }

        Notification::make()
            ->title('Webhook URL copied')
            ->body("Copied {$gateway} webhook URL to clipboard.")
            ->success()
            ->send();
    }
}
