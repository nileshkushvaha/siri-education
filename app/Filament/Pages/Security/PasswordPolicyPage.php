<?php

declare(strict_types=1);

namespace App\Filament\Pages\Security;

use App\Services\Security\SecuritySettingsService;
use App\Settings\PasswordPolicySettings;
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

class PasswordPolicyPage extends Page
{
    use HasSecurityAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Password Policy';

    protected static string|\UnitEnum|null $navigationGroup = 'Users & Access';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'security/password-policy';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected static function securityPermission(): string
    {
        return 'security.password_policy.view';
    }

    public static function getLabel(): string
    {
        return 'Password Policy';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Password Policy';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Enforce password quality and expiry requirements for all users.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '/admin/security/password-policy' => 'Security',
            '#' => 'Password Policy',
        ];
    }

    public function mount(): void
    {
        $s = app(PasswordPolicySettings::class);

        $this->form->fill([
            'min_length' => $s->min_length,
            'require_uppercase' => $s->require_uppercase,
            'require_lowercase' => $s->require_lowercase,
            'require_number' => $s->require_number,
            'require_special' => $s->require_special,
            'prevent_reuse' => $s->prevent_reuse,
            'password_history_count' => $s->password_history_count,
            'expiry_enabled' => $s->expiry_enabled,
            'expiry_days' => $s->expiry_days,
            'force_change_on_first_login' => $s->force_change_on_first_login,
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

                Section::make('Password Rules')
                    ->description('Minimum complexity requirements applied when a user sets or changes their password.')
                    ->schema([
                        TextInput::make('min_length')
                            ->label('Minimum Password Length')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(128)
                            ->required()
                            ->suffix('characters'),

                        Toggle::make('require_uppercase')
                            ->label('Require Uppercase Letter'),

                        Toggle::make('require_lowercase')
                            ->label('Require Lowercase Letter'),

                        Toggle::make('require_number')
                            ->label('Require Number'),

                        Toggle::make('require_special')
                            ->label('Require Special Character')
                            ->helperText('e.g. ! @ # $ % ^ & *'),
                    ]),

                Section::make('Password History')
                    ->description('Prevent users from reusing recent passwords.')
                    ->schema([
                        Toggle::make('prevent_reuse')
                            ->label('Prevent Password Reuse')
                            ->live()
                            ->helperText('Enforcement requires the password history table (future).'),

                        TextInput::make('password_history_count')
                            ->label('Password History Count')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(24)
                            ->required()
                            ->visible(fn ($get) => (bool) $get('prevent_reuse'))
                            ->helperText('Number of previous passwords to remember.'),
                    ]),

                Section::make('Password Expiry')
                    ->description('Force users to change their password periodically.')
                    ->schema([
                        Toggle::make('expiry_enabled')
                            ->label('Enable Password Expiry')
                            ->live()
                            ->helperText('Enforcement requires password_expires_at on users (future).'),

                        TextInput::make('expiry_days')
                            ->label('Password Expiry Days')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->required()
                            ->visible(fn ($get) => (bool) $get('expiry_enabled'))
                            ->suffix('days'),
                    ]),

                Section::make('First Login')
                    ->description('When enabled, newly created users must change their password before accessing the application.')
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('force_change_on_first_login')
                            ->label('Force Password Change On First Login')
                            ->helperText('New users created by an administrator will be required to set a new password on their first login.'),
                    ]),

            ]),
        ]);
    }

    public function save(): void
    {
        Gate::authorize('security.password_policy.update');

        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        if (! app(SecuritySettingsService::class)->savePasswordPolicy($data)) {
            return;
        }

        Notification::make()->title('Password policy saved')->success()->send();
    }
}
