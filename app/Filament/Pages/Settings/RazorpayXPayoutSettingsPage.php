<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Earnings\Providers\RazorpayX\RazorpayXInstructorPayoutProvider;
use App\Earnings\Providers\RazorpayX\RazorpayXPayoutConfigurationValidator;
use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Settings\RazorpayXPayoutSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\HtmlString;

/**
 * RazorpayX India/INR instructor payout provider configuration.
 * Deliberately separate from InstructorEarningSettingsPage
 * (payout policy) and PaymentSettingsPage (student collection
 * gateways) — this page owns provider credentials and provisioning
 * controls only. Enabling `razorpayx_enabled` here does NOT enable
 * payout execution — that remains
 * InstructorEarningSettings::payout_execution_enabled, gated by its own
 * preflight on the Instructor Earnings settings page. Secrets are never
 * re-displayed after submission — every credential field starts blank
 * on mount and is only written when the admin types a new value.
 */
class RazorpayXPayoutSettingsPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use LogsSettingsUpdates;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'RazorpayX Payouts';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'settings/razorpayx-payout';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getLabel(): string
    {
        return 'RazorpayX Payout Settings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'RazorpayX Payout Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'India/INR instructor payout provider configuration. No live payout is ever executed from this page.';
    }

    public function mount(): void
    {
        $settings = app(RazorpayXPayoutSettings::class);

        $this->form->fill([
            'razorpayx_enabled' => $settings->razorpayx_enabled,
            'razorpayx_environment' => $settings->razorpayx_environment,
            'razorpayx_key_id' => $settings->razorpayx_key_id,
            // Secrets are never re-displayed — left blank on mount.
            'razorpayx_key_secret' => null,
            'razorpayx_webhook_secret' => null,
            'razorpayx_account_number' => $settings->razorpayx_account_number,
            'razorpayx_default_mode' => $settings->razorpayx_default_mode,
            'razorpayx_default_purpose' => $settings->razorpayx_default_purpose,
            'razorpayx_queue_if_low_balance' => $settings->razorpayx_queue_if_low_balance,
            'razorpayx_expected_outbound_ips' => implode("\n", $settings->razorpayx_expected_outbound_ips),
            'razorpayx_contact_provisioning_enabled' => $settings->razorpayx_contact_provisioning_enabled,
            'razorpayx_fund_account_provisioning_enabled' => $settings->razorpayx_fund_account_provisioning_enabled,
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
                Section::make('Status')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('status_overview')
                            ->hiddenLabel()
                            ->content(fn (): HtmlString => $this->statusOverview()),
                        ActionsComponent::make([
                            Action::make('validate_configuration')
                                ->label('Validate configuration')
                                ->color('gray')
                                ->action('validateConfiguration'),
                            Action::make('check_health')
                                ->label('Check health')
                                ->color('gray')
                                ->visible(fn (): bool => auth()->user()?->can('TestConnection:RazorpayXPayout') ?? false)
                                ->action('checkHealth'),
                            Action::make('confirm_ip_allowlisting')
                                ->label('Confirm IP allowlisting')
                                ->color('warning')
                                ->requiresConfirmation()
                                ->modalDescription('Confirm that every IP address listed below has been added to the RazorpayX dashboard allowlist. This is a manual, explicit attestation — it is never inferred from the field merely being filled in.')
                                ->visible(fn (): bool => auth()->user()?->can('ConfirmIpAllowlisting:RazorpayXPayout') ?? false)
                                ->action('confirmIpAllowlisting'),
                            Action::make('rotate_webhook_secret')
                                ->label('Rotate webhook secret')
                                ->color('gray')
                                ->form([
                                    TextInput::make('new_secret')
                                        ->label('New webhook secret')
                                        ->password()
                                        ->revealable()
                                        ->required(),
                                ])
                                ->visible(fn (): bool => auth()->user()?->can('Configure:RazorpayXPayout') ?? false)
                                ->action(function (array $data): void {
                                    $this->rotateWebhookSecret($data['new_secret']);
                                }),
                            Action::make('clear_invalid_credentials')
                                ->label('Clear invalid credentials')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalDescription('Clears key_secret, webhook_secret, and the previous webhook_secret. Key ID and the source account number are left untouched.')
                                ->visible(fn (): bool => auth()->user()?->can('Configure:RazorpayXPayout') ?? false)
                                ->action('clearInvalidCredentials'),
                        ])->key('status-actions'),
                    ]),

                Section::make('Webhook')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('webhook_url')
                            ->label('Webhook URL')
                            ->content(fn (): string => route('api.payouts.razorpayx.webhook')),
                    ]),

                Section::make('Provider Credentials')
                    ->description('Never redisplayed after submission. Leave a secret field blank to keep its current stored value.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('razorpayx_enabled')
                                ->label('RazorpayX enabled')
                                ->helperText('A plain provider toggle — not the payout execution kill switch. See Instructor Earnings settings for that.'),
                            Select::make('razorpayx_environment')
                                ->label('Environment')
                                ->options(['test' => 'Test', 'live' => 'Live'])
                                ->required()
                                ->native(false),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('razorpayx_key_id')
                                ->label('Key ID')
                                ->helperText('e.g. rzp_test_xxxxxxxx or rzp_live_xxxxxxxx.'),
                            TextInput::make('razorpayx_account_number')
                                ->label('Source account number')
                                ->helperText('The RazorpayX business account payouts are debited from. Not a bank detail.'),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('razorpayx_key_secret')
                                ->label('Key secret')
                                ->password()
                                ->revealable(),
                            TextInput::make('razorpayx_webhook_secret')
                                ->label('Webhook secret')
                                ->password()
                                ->revealable()
                                ->helperText('Use "Rotate webhook secret" below to change this without a signature-verification gap.'),
                        ]),
                    ]),

                Section::make('Payout Defaults')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('razorpayx_default_mode')
                                ->label('Default transfer mode')
                                ->options(['IMPS' => 'IMPS', 'NEFT' => 'NEFT', 'RTGS' => 'RTGS'])
                                ->required()
                                ->native(false),
                            Select::make('razorpayx_default_purpose')
                                ->label('Default purpose')
                                ->options([
                                    'payout' => 'Payout', 'refund' => 'Refund', 'salary' => 'Salary',
                                    'utility bill' => 'Utility bill', 'vendor bill' => 'Vendor bill',
                                ])
                                ->required()
                                ->native(false),
                            Toggle::make('razorpayx_queue_if_low_balance')
                                ->label('Queue if low balance')
                                ->helperText('Default OFF — insufficient balance stays visible to finance rather than silently queuing.'),
                        ]),
                    ]),

                Section::make('IP Allowlisting')
                    ->description('RazorpayX requires payout API calls to originate from allowlisted IPs. List the addresses below, then use "Confirm IP allowlisting" above once they are actually added in the RazorpayX dashboard.')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('razorpayx_expected_outbound_ips')
                            ->label('Expected outbound IP addresses')
                            ->helperText('One per line.')
                            ->rows(3),
                    ]),

                Section::make('Destination Provisioning')
                    ->description('Contact and Fund Account provisioning are separate switches from RazorpayX being enabled — both default OFF.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('razorpayx_contact_provisioning_enabled')
                                ->label('Contact provisioning enabled'),
                            Toggle::make('razorpayx_fund_account_provisioning_enabled')
                                ->label('Fund Account provisioning enabled'),
                        ]),
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

        $saved = $this->saveSettingsWithAudit(RazorpayXPayoutSettings::class, 'razorpayx_payout', function (RazorpayXPayoutSettings $settings) use ($data): void {
            $settings->razorpayx_enabled = (bool) $data['razorpayx_enabled'];
            $settings->razorpayx_environment = $data['razorpayx_environment'];
            $settings->razorpayx_key_id = filled($data['razorpayx_key_id']) ? $data['razorpayx_key_id'] : null;
            $settings->razorpayx_account_number = filled($data['razorpayx_account_number']) ? $data['razorpayx_account_number'] : null;
            $settings->razorpayx_default_mode = $data['razorpayx_default_mode'];
            $settings->razorpayx_default_purpose = $data['razorpayx_default_purpose'];
            $settings->razorpayx_queue_if_low_balance = (bool) $data['razorpayx_queue_if_low_balance'];
            $settings->razorpayx_expected_outbound_ips = $this->parseIps($data['razorpayx_expected_outbound_ips'] ?? '');
            $settings->razorpayx_contact_provisioning_enabled = (bool) $data['razorpayx_contact_provisioning_enabled'];
            $settings->razorpayx_fund_account_provisioning_enabled = (bool) $data['razorpayx_fund_account_provisioning_enabled'];

            // Secrets are never re-displayed — a blank submission means
            // "keep the existing stored value", same rule as the Payment
            // Gateway and Meeting settings pages.
            if (filled($data['razorpayx_key_secret'] ?? null)) {
                $settings->razorpayx_key_secret = Crypt::encryptString((string) $data['razorpayx_key_secret']);
            }

            if (filled($data['razorpayx_webhook_secret'] ?? null)) {
                $settings->razorpayx_webhook_secret = Crypt::encryptString((string) $data['razorpayx_webhook_secret']);
            }
        });

        if (! $saved) {
            return;
        }

        Notification::make()->title('RazorpayX settings saved')->success()->send();

        $this->mount();
    }

    public function validateConfiguration(): void
    {
        $issues = app(RazorpayXPayoutConfigurationValidator::class)->issues(app(RazorpayXPayoutSettings::class));

        $saved = $this->saveSettingsWithAudit(RazorpayXPayoutSettings::class, 'razorpayx_payout', function (RazorpayXPayoutSettings $settings) use ($issues): void {
            $settings->razorpayx_config_status = $issues === [] ? 'ready' : 'invalid';
            $settings->razorpayx_last_checked_at = now()->toIso8601String();
        });

        if (! $saved) {
            return;
        }

        if ($issues !== []) {
            Notification::make()
                ->title('Configuration is not structurally valid')
                ->body(implode(' ', $issues))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->title('Configuration looks structurally valid')->success()->send();
    }

    public function checkHealth(): void
    {
        // The external probe's result is the data being persisted, so it
        // necessarily happens before the atomic save — never inside the
        // settings transaction itself (no provider call is ever wrapped
        // in the audited DB transaction).
        $health = app(RazorpayXInstructorPayoutProvider::class)->healthCheck();

        $saved = $this->saveSettingsWithAudit(RazorpayXPayoutSettings::class, 'razorpayx_payout', function (RazorpayXPayoutSettings $settings) use ($health): void {
            $settings->razorpayx_last_health_check_at = now()->toIso8601String();
            $settings->razorpayx_last_health_status = $health->healthy ? 'healthy' : 'unhealthy';
        });

        if (! $saved) {
            return;
        }

        if (! $health->healthy) {
            Notification::make()
                ->title('RazorpayX health check failed')
                ->body($health->safeMessage ?? 'RazorpayX could not be reached.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title('RazorpayX is reachable')->success()->send();
    }

    public function confirmIpAllowlisting(): void
    {
        $adminId = auth()->id();

        $saved = $this->saveSettingsWithAudit(RazorpayXPayoutSettings::class, 'razorpayx_payout', function (RazorpayXPayoutSettings $settings) use ($adminId): void {
            $settings->razorpayx_ip_allowlisting_confirmed_at = now()->toIso8601String();
            $settings->razorpayx_ip_allowlisting_confirmed_by = $adminId;
        });

        if (! $saved) {
            return;
        }

        Notification::make()->title('IP allowlisting confirmed')->success()->send();
    }

    private function rotateWebhookSecret(string $newSecret): void
    {
        $saved = $this->saveSettingsWithAudit(RazorpayXPayoutSettings::class, 'razorpayx_payout', function (RazorpayXPayoutSettings $settings) use ($newSecret): void {
            $settings->razorpayx_previous_webhook_secret = $settings->razorpayx_webhook_secret;
            $settings->razorpayx_webhook_secret = Crypt::encryptString($newSecret);
        });

        if (! $saved) {
            return;
        }

        Notification::make()
            ->title('Webhook secret rotated')
            ->body('The previous secret remains accepted during the rotation window.')
            ->success()
            ->send();
    }

    public function clearInvalidCredentials(): void
    {
        $saved = $this->saveSettingsWithAudit(RazorpayXPayoutSettings::class, 'razorpayx_payout', function (RazorpayXPayoutSettings $settings): void {
            $settings->razorpayx_key_secret = null;
            $settings->razorpayx_webhook_secret = null;
            $settings->razorpayx_previous_webhook_secret = null;
            $settings->razorpayx_config_status = 'not_configured';
        });

        if (! $saved) {
            return;
        }

        Notification::make()->title('Credentials cleared')->success()->send();
    }

    /** @return list<string> */
    private function parseIps(string $raw): array
    {
        return collect(preg_split('/[\r\n,]+/', $raw) ?: [])
            ->map(fn (string $ip): string => trim($ip))
            ->filter()
            ->values()
            ->all();
    }

    private function statusOverview(): HtmlString
    {
        $settings = app(RazorpayXPayoutSettings::class);

        $line = fn (string $label, string $value): string => sprintf('<div class="flex justify-between gap-8"><span>%s</span><strong>%s</strong></div>', e($label), e($value));

        return new HtmlString(
            '<div class="grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">'
            .$line('Configuration status', $settings->razorpayx_config_status)
            .$line('Last validated', $settings->razorpayx_last_checked_at ?? 'never')
            .$line('Last health check', $settings->razorpayx_last_health_check_at ?? 'never')
            .$line('Last health status', $settings->razorpayx_last_health_status)
            .$line('IP allowlisting confirmed', $settings->razorpayx_ip_allowlisting_confirmed_at ?? 'not confirmed')
            .'</div>'
        );
    }
}
