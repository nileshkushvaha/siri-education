<?php

declare(strict_types=1);

namespace App\Filament\Pages\Security;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Services\Security\SecuritySettingsService;
use App\Settings\AuthenticationSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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

class AuthenticationPage extends Page
{
    use HasCentralizedNavigation;
    use HasSecurityAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Authentication';

    protected static string|\UnitEnum|null $navigationGroup = 'Users & Access';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'security/authentication';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected static function securityPermission(): string
    {
        return 'security.authentication.view';
    }

    public static function getLabel(): string
    {
        return 'Authentication';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Authentication';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Control how users authenticate into the application.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '/admin/security/authentication' => 'Security',
            '#' => 'Authentication',
        ];
    }

    public function mount(): void
    {
        $s = app(AuthenticationSettings::class);

        $this->form->fill([
            'login_enabled' => $s->login_enabled,
            'remember_me_enabled' => $s->remember_me_enabled,
            'email_verification_required' => $s->email_verification_required,
            'default_login_method' => $s->default_login_method,
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

                Section::make('General')
                    ->description('Core login behaviour for all users.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('login_enabled')
                                ->label('Enable Login')
                                ->helperText('When disabled, the login page returns a maintenance message.')
                                ->onColor('success')
                                ->offColor('danger'),

                            Toggle::make('remember_me_enabled')
                                ->label('Enable Remember Me')
                                ->helperText('Allow users to stay logged in across browser sessions.'),
                        ]),

                        Grid::make(2)->schema([
                            Toggle::make('email_verification_required')
                                ->label('Require Email Verification')
                                ->helperText('New accounts must verify their email before they can log in.'),

                            Select::make('default_login_method')
                                ->label('Default Login Method')
                                ->options([
                                    'email' => 'Email',
                                    'username' => 'Username (future)',
                                    'email_or_username' => 'Email or Username (future)',
                                ])
                                ->disableOptionWhen(fn (string $value) => in_array($value, ['username', 'email_or_username']))
                                ->native(false)
                                ->required(),
                        ]),
                    ]),

                Section::make('Future Authentication Methods')
                    ->description('These options are not yet available. They are shown here as a roadmap.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            Toggle::make('_future_2fa')
                                ->label('Two Factor Authentication')
                                ->disabled()
                                ->helperText('Coming soon'),

                            Toggle::make('_future_passkeys')
                                ->label('Passkeys')
                                ->disabled()
                                ->helperText('Coming soon'),

                            Toggle::make('_future_social')
                                ->label('Social Login')
                                ->disabled()
                                ->helperText('Coming soon'),

                            Toggle::make('_future_ldap')
                                ->label('LDAP')
                                ->disabled()
                                ->helperText('Coming soon'),

                            Toggle::make('_future_saml')
                                ->label('SAML')
                                ->disabled()
                                ->helperText('Coming soon'),

                            Toggle::make('_future_azure')
                                ->label('Azure AD')
                                ->disabled()
                                ->helperText('Coming soon'),
                        ]),
                    ]),
            ]),
        ]);
    }

    public function save(): void
    {
        Gate::authorize('security.authentication.update');

        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        if (! app(SecuritySettingsService::class)->saveAuthentication($data)) {
            return;
        }

        Notification::make()->title('Authentication settings saved')->success()->send();
    }
}
