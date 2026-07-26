<?php

declare(strict_types=1);

namespace App\Filament\Pages\Security;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Services\Security\AdminSessionService;
use App\Services\Security\SecuritySettingsService;
use App\Settings\SessionSettings;
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

class SessionPage extends Page
{
    use HasCentralizedNavigation;
    use HasSecurityAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Session';

    protected static string|\UnitEnum|null $navigationGroup = 'Users & Access';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'security/session';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected static function securityPermission(): string
    {
        return 'security.session.view';
    }

    public static function getLabel(): string
    {
        return 'Session';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Session';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Manage user session lifetime and device access.';
    }

    public function getBreadcrumbs(): array
    {
        return [
            '/admin' => 'Dashboard',
            '/admin/security/session' => 'Security',
            '#' => 'Session',
        ];
    }

    public function mount(): void
    {
        $s = app(SessionSettings::class);

        $this->form->fill([
            'idle_timeout' => $s->idle_timeout,
            'allow_multiple_sessions' => $s->allow_multiple_sessions,
            'force_logout_on_password_change' => $s->force_logout_on_password_change,
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

                        Action::make('forceLogoutAll')
                            ->label('Force Logout All Devices')
                            ->color('danger')
                            ->icon(Heroicon::OutlinedArrowRightOnRectangle)
                            ->requiresConfirmation()
                            ->modalHeading('Force logout all devices?')
                            ->modalDescription(
                                'This will immediately terminate every active session for every user, '.
                                'including yours. All users will be required to log in again. '.
                                'This action cannot be undone.'
                            )
                            ->modalSubmitActionLabel('Yes, log everyone out')
                            ->action('forceLogoutAll'),
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

                Section::make('Session Timeout')
                    ->description('How long an idle session remains active before the user is logged out.')
                    ->schema([
                        TextInput::make('idle_timeout')
                            ->label('Idle Timeout')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(1440)
                            ->required()
                            ->suffix('minutes')
                            ->helperText('Set to 0 to disable idle timeout (not recommended).'),
                    ]),

                Section::make('Devices')
                    ->description('Control how many active sessions a user may maintain.')
                    ->schema([
                        Toggle::make('allow_multiple_sessions')
                            ->label('Allow Multiple Sessions')
                            ->helperText('When disabled, logging in on a new device terminates all other sessions.'),

                        Toggle::make('force_logout_on_password_change')
                            ->label('Force Logout On Password Change')
                            ->helperText('Immediately invalidates all other sessions when a user changes their password.'),
                    ]),

                Section::make('Future Options')
                    ->description('Device-management features are not yet available.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('_future_device_mgmt')
                                ->label('Device Management')
                                ->disabled()
                                ->helperText('Coming soon'),

                            Toggle::make('_future_trusted_devices')
                                ->label('Trusted Devices')
                                ->disabled()
                                ->helperText('Coming soon'),
                        ]),
                    ]),

            ]),
        ]);
    }

    public function save(): void
    {
        Gate::authorize('security.session.update');

        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        if (! app(SecuritySettingsService::class)->saveSession($data)) {
            return;
        }

        Notification::make()->title('Session settings saved')->success()->send();
    }

    public function forceLogoutAll(): void
    {
        Gate::authorize('security.session.update');

        app(AdminSessionService::class)->forceLogoutAllDevices();

        Notification::make()
            ->title('All sessions terminated')
            ->body('Every active user session has been invalidated.')
            ->warning()
            ->send();
    }
}
