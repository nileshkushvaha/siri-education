<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Notifications\Support\TestMailConfigurationNotification;
use App\Services\Mail\TransactionalNotificationService;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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

class MailSettingsPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use HasSettingsSectionBreadcrumb;
    use LogsSettingsUpdates;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Mail';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'settings/mail';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?string $testEmail = null;

    public static function getLabel(): string
    {
        return 'Mail Settings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Mail Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Configure SMTP settings for outgoing email. Passwords are encrypted before storage.';
    }

    public function mount(): void
    {
        $settings = app(MailSettings::class);

        $this->form->fill([
            'from_name' => $settings->from_name,
            'from_email' => $settings->from_email,
            'transactional_domain' => $settings->transactional_domain,
            'auth_from_name' => $settings->auth_from_name,
            'auth_from_email' => $settings->auth_from_email,
            'booking_from_name' => $settings->booking_from_name,
            'booking_from_email' => $settings->booking_from_email,
            'payment_from_name' => $settings->payment_from_name,
            'payment_from_email' => $settings->payment_from_email,
            'tutor_from_name' => $settings->tutor_from_name,
            'tutor_from_email' => $settings->tutor_from_email,
            'wallet_from_name' => $settings->wallet_from_name,
            'wallet_from_email' => $settings->wallet_from_email,
            'support_from_name' => $settings->support_from_name,
            'support_from_email' => $settings->support_from_email,
            'admin_from_name' => $settings->admin_from_name,
            'admin_from_email' => $settings->admin_from_email,
            'driver' => $settings->driver,
            'host' => $settings->host,
            'port' => $settings->port,
            'username' => $settings->username,
            'password' => null, // never prefill password
            'encryption' => $settings->encryption,
            'queue_emails' => $settings->queue_emails,
            'connection_timeout' => $settings->connection_timeout,
            'retry_attempts' => $settings->retry_attempts,
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
                            ->label('Save Mail Settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),

                        Action::make('test')
                            ->label('Send Test Email')
                            ->icon(Heroicon::OutlinedPaperAirplane)
                            ->color('info')
                            ->requiresConfirmation()
                            ->modalHeading('Send Test Email')
                            ->modalDescription('This will send a test email using the currently saved settings.')
                            ->form([
                                TextInput::make('test_email')
                                    ->label('Send test to')
                                    ->email()
                                    ->required()
                                    ->default(fn () => auth()->user()?->email),
                            ])
                            ->action(function (array $data) {
                                $this->sendTestEmail($data['test_email']);
                            }),
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

                // ── Sender Information ─────────────────────────── full width
                Section::make('Sender Information')
                    ->description('The name and email address shown to recipients.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('from_name')
                                ->label('From Name')
                                ->required()
                                ->maxLength(100)
                                ->placeholder('SIRI Education'),

                            TextInput::make('from_email')
                                ->label('From Email')
                                ->email()
                                ->required()
                                ->maxLength(150)
                                ->placeholder('noreply@example.com'),
                        ]),
                    ]),

                // ── SMTP Configuration ─────────────────────────── full width
                Section::make('SMTP Configuration')
                    ->description('Connection settings for your mail server.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(4)->schema([
                            Select::make('driver')
                                ->label('Mail Driver')
                                ->options([
                                    'smtp' => 'SMTP',
                                    'resend' => 'Resend',
                                    'sendmail' => 'Sendmail',
                                    'log' => 'Log only (does not send real email)',
                                    'array' => 'Test mode (does not send real email)',
                                ])
                                ->native(false)
                                ->required()
                                ->live(),

                            Select::make('encryption')
                                ->label('Encryption')
                                ->options([
                                    'tls' => 'TLS (recommended)',
                                    'ssl' => 'SSL',
                                    'none' => 'None',
                                ])
                                ->native(false)
                                ->required(),

                            TextInput::make('host')
                                ->label('SMTP Host')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('smtp.mailtrap.io')
                                ->columnSpan(1),

                            TextInput::make('port')
                                ->label('SMTP Port')
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->maxValue(65535)
                                ->placeholder('587'),
                        ]),

                        Grid::make(2)->schema([
                            TextInput::make('username')
                                ->label('Username')
                                ->maxLength(255)
                                ->autocomplete('off'),

                            TextInput::make('password')
                                ->label('Password')
                                ->password()
                                ->revealable()
                                ->maxLength(255)
                                ->autocomplete('new-password')
                                ->helperText('Leave blank to keep the existing password. Stored encrypted.')
                                ->dehydrated(fn ($state) => filled($state)),
                        ]),
                    ]),

                Section::make('Transactional Senders')
                    ->description('Verified domain sender addresses used by queued transactional notifications.')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('transactional_domain')
                            ->label('Verified Sending Domain')
                            ->placeholder('example.com')
                            ->maxLength(255),

                        Grid::make(2)->schema($this->senderFields('auth', 'Auth')),
                        Grid::make(2)->schema($this->senderFields('booking', 'Booking')),
                        Grid::make(2)->schema($this->senderFields('payment', 'Payment')),
                        Grid::make(2)->schema($this->senderFields('tutor', 'Tutor')),
                        Grid::make(2)->schema($this->senderFields('wallet', 'Wallet')),
                        Grid::make(2)->schema($this->senderFields('support', 'Support')),
                        Grid::make(2)->schema($this->senderFields('admin', 'Admin Alerts')),
                    ]),

                // ── Email Queue ────────────────────────────────────── left
                Section::make('Email Queue')
                    ->description('Control whether emails are sent synchronously or queued.')
                    ->schema([
                        Toggle::make('queue_emails')
                            ->label('Queue Emails')
                            ->helperText('Send emails asynchronously via the queue worker. Requires a running queue worker.')
                            ->onColor('success'),
                    ]),

                // ── Advanced ──────────────────────────────────────── right
                Section::make('Advanced')
                    ->description('Timeout and retry configuration.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('connection_timeout')
                                ->label('Connection Timeout (seconds)')
                                ->numeric()
                                ->required()
                                ->minValue(5)
                                ->maxValue(300)
                                ->default(30),

                            TextInput::make('retry_attempts')
                                ->label('Retry Attempts')
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->maxValue(10)
                                ->default(3),
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

        $saved = $this->saveSettingsWithAudit(MailSettings::class, 'settings', function (MailSettings $settings) use ($data): void {
            $settings->from_name = $data['from_name'];
            $settings->from_email = $data['from_email'];
            $settings->transactional_domain = $data['transactional_domain'] ?? null;
            foreach (['auth', 'booking', 'payment', 'tutor', 'wallet', 'support', 'admin'] as $area) {
                $settings->{"{$area}_from_name"} = $data["{$area}_from_name"] ?? $settings->from_name;
                $settings->{"{$area}_from_email"} = $data["{$area}_from_email"] ?? $settings->from_email;
            }
            $settings->driver = $data['driver'];
            $settings->host = $data['host'];
            $settings->port = (int) $data['port'];
            $settings->username = $data['username'] ?? null;
            $settings->encryption = $data['encryption'];
            $settings->queue_emails = (bool) ($data['queue_emails'] ?? false);
            $settings->connection_timeout = (int) ($data['connection_timeout'] ?? 30);
            $settings->retry_attempts = (int) ($data['retry_attempts'] ?? 3);

            // Only update password if a new one was provided — encrypt it
            if (filled($data['password'] ?? null)) {
                $settings->password = Crypt::encryptString($data['password']);
            }
        });

        if (! $saved) {
            return;
        }

        Notification::make()
            ->title('Mail settings saved')
            ->success()
            ->send();
    }

    public function sendTestEmail(string $to): void
    {
        try {
            $settings = app(MailSettings::class);

            // Temporarily override mail config with saved settings
            config([
                'mail.default' => $settings->driver,
                'mail.mailers.smtp.host' => $settings->host,
                'mail.mailers.smtp.port' => $settings->port,
                'mail.mailers.smtp.username' => $settings->username,
                'mail.mailers.smtp.password' => $settings->password
                    ? Crypt::decryptString($settings->password)
                    : null,
                'mail.mailers.smtp.encryption' => $settings->encryption === 'none' ? null : $settings->encryption,
                'mail.from.address' => $settings->from_email,
                'mail.from.name' => $settings->from_name,
            ]);

            app(TransactionalNotificationService::class)
                ->routeMail($to, new TestMailConfigurationNotification(app(GeneralSettings::class)->app_name));

            Notification::make()
                ->title('Test email sent')
                ->body("A test email was sent to {$to}")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Failed to send test email')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return array<int, TextInput>
     */
    private function senderFields(string $key, string $label): array
    {
        return [
            TextInput::make("{$key}_from_name")
                ->label("{$label} From Name")
                ->required()
                ->maxLength(100),
            TextInput::make("{$key}_from_email")
                ->label("{$label} From Email")
                ->email()
                ->required()
                ->maxLength(150),
        ];
    }
}
