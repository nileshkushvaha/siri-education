<?php

declare(strict_types=1);

namespace App\Filament\Pages\Security;

use App\Services\Security\SecuritySettingsService;
use App\Settings\RegistrationSettings;
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
use Spatie\Permission\Models\Role;

class RegistrationPage extends Page
{
    use HasSecurityAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $navigationLabel = 'Registration';

    protected static string|\UnitEnum|null $navigationGroup = 'Users & Access';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'security/registration';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected static function securityPermission(): string
    {
        return 'security.registration.view';
    }

    public static function getLabel(): string
    {
        return 'Registration';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Registration';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Control how new accounts are created and who can register.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '/admin/security/registration' => 'Security',
            '#' => 'Registration',
        ];
    }

    public function mount(): void
    {
        $s = app(RegistrationSettings::class);

        $this->form->fill([
            'self_registration_enabled' => $s->self_registration_enabled,
            'default_role' => $s->default_role,
            'require_admin_approval' => $s->require_admin_approval,
            'send_welcome_email' => $s->send_welcome_email,
            'auto_verify_email' => $s->auto_verify_email,
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

                Section::make('Registration')
                    ->description('Configure who can create a new account and what happens after sign-up.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('self_registration_enabled')
                                ->label('Enable Self Registration')
                                ->helperText('Allow visitors to create their own accounts.')
                                ->live(),

                            Select::make('default_role')
                                ->label('Default Role')
                                ->placeholder('— Select a role —')
                                ->options(fn () => Role::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'name')
                                    ->all()
                                )
                                ->searchable()
                                ->native(false)
                                ->nullable()
                                ->helperText('Role automatically assigned to new registrations.'),
                        ]),

                        Grid::make(2)->schema([
                            Toggle::make('require_admin_approval')
                                ->label('Require Admin Approval')
                                ->helperText('New accounts are set to pending until an administrator approves them.'),

                            Toggle::make('send_welcome_email')
                                ->label('Send Welcome Email')
                                ->helperText('Send a welcome email to the user after successful registration.'),
                        ]),

                        Toggle::make('auto_verify_email')
                            ->label('Auto Verify Email')
                            ->helperText('Skip the email verification step. Intended for internal or single-tenant systems.'),
                    ]),

                Section::make('Future Options')
                    ->description('Advanced registration controls — not yet available.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('_future_invite_only')
                                ->label('Invitation Only Registration')
                                ->disabled()
                                ->helperText('Coming soon'),

                            Toggle::make('_future_domain_restriction')
                                ->label('Domain Restrictions')
                                ->disabled()
                                ->helperText('Coming soon'),
                        ]),
                    ]),

            ]),
        ]);
    }

    public function save(): void
    {
        Gate::authorize('security.registration.update');

        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        if (! app(SecuritySettingsService::class)->saveRegistration($data)) {
            return;
        }

        Notification::make()->title('Registration settings saved')->success()->send();
    }
}
