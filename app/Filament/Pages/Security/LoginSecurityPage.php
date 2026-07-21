<?php

declare(strict_types=1);

namespace App\Filament\Pages\Security;

use App\Services\Security\SecuritySettingsService;
use App\Settings\LoginSecuritySettings;
use BackedEnum;
use Filament\Actions\Action;
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
use Illuminate\Support\Facades\Gate;

class LoginSecurityPage extends Page
{
    use HasSecurityAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $navigationLabel = 'Login Security';

    protected static string|\UnitEnum|null $navigationGroup = 'Users & Access';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'security/login-security';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected static function securityPermission(): string
    {
        return 'security.login_security.view';
    }

    public static function getLabel(): string
    {
        return 'Login Security';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Login Security';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Protect against brute-force attacks and unauthorised login attempts.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '/admin/security/login-security' => 'Security',
            '#' => 'Login Security',
        ];
    }

    public function mount(): void
    {
        $s = app(LoginSecuritySettings::class);

        $this->form->fill([
            'max_failed_attempts' => $s->max_failed_attempts,
            'lockout_duration' => $s->lockout_duration,
            'throttling_enabled' => $s->throttling_enabled,
            'reset_throttling_enabled' => $s->reset_throttling_enabled,
            'notify_user_on_failed' => $s->notify_user_on_failed,
            'notify_admin_on_lock' => $s->notify_admin_on_lock,
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

                Section::make('Login Attempts')
                    ->description('Automatically lock accounts after repeated failed logins.')
                    ->schema([
                        TextInput::make('max_failed_attempts')
                            ->label('Maximum Failed Login Attempts')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(20)
                            ->required()
                            ->suffix('attempts')
                            ->helperText('Account is locked after this many consecutive failures.'),

                        TextInput::make('lockout_duration')
                            ->label('Lockout Duration')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(1440)
                            ->required()
                            ->suffix('minutes')
                            ->helperText('How long the account stays locked. Set to 0 for manual unlock only.'),
                    ]),

                Section::make('Rate Limiting')
                    ->description('Throttle login and password reset requests to slow automated attacks.')
                    ->schema([
                        Toggle::make('throttling_enabled')
                            ->label('Enable Login Throttling')
                            ->helperText('Applies Laravel\'s built-in rate limiting to the login endpoint.'),

                        Toggle::make('reset_throttling_enabled')
                            ->label('Enable Password Reset Throttling')
                            ->helperText('Limits how frequently a user can request a password reset email.'),
                    ]),

                Section::make('Notifications')
                    ->description('Alert users and admins when suspicious login activity is detected.')
                    ->schema([
                        Toggle::make('notify_user_on_failed')
                            ->label('Notify User On Failed Attempts')
                            ->helperText('Send the account owner an email when a failed login is recorded.'),

                        Toggle::make('notify_admin_on_lock')
                            ->label('Notify Admin On Account Lock')
                            ->helperText('Send the site administrator an alert when an account is locked out.'),
                    ]),

                Section::make('Future Options')
                    ->description('Bot-detection integrations — not yet available.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('_future_recaptcha')
                                ->label('Google reCAPTCHA')
                                ->disabled()
                                ->helperText('Coming soon'),

                            Toggle::make('_future_turnstile')
                                ->label('Cloudflare Turnstile')
                                ->disabled()
                                ->helperText('Coming soon'),
                        ]),
                    ]),

            ]),
        ]);
    }

    public function save(): void
    {
        Gate::authorize('security.login_security.update');

        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        if (! app(SecuritySettingsService::class)->saveLoginSecurity($data)) {
            return;
        }

        Notification::make()->title('Login security settings saved')->success()->send();
    }
}
