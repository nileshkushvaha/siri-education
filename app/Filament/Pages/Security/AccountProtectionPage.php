<?php

declare(strict_types=1);

namespace App\Filament\Pages\Security;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use App\Services\Security\SecuritySettingsService;
use App\Settings\AccountProtectionSettings;
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

class AccountProtectionPage extends Page
{
    use HasCentralizedNavigation;
    use HasSecurityAccess;
    use HasSettingsSectionBreadcrumb;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?string $navigationLabel = 'Account Protection';

    protected static string|\UnitEnum|null $navigationGroup = 'Users & Access';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'security/account-protection';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected static function securityPermission(): string
    {
        return 'security.account_protection.view';
    }

    public static function getLabel(): string
    {
        return 'Account Protection';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Account Protection';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Configure account lock behaviour and alert notifications.';
    }

    public function mount(): void
    {
        $s = app(AccountProtectionSettings::class);

        $this->form->fill([
            'disable_after_failed_attempts' => $s->disable_after_failed_attempts,
            'auto_unlock_after' => $s->auto_unlock_after,
            'notify_user' => $s->notify_user,
            'notify_admin' => $s->notify_admin,
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

                Section::make('Account Lock')
                    ->description('Automatically protect accounts against sustained brute-force attempts.')
                    ->schema([
                        Toggle::make('disable_after_failed_attempts')
                            ->label('Disable Account After Failed Attempts')
                            ->live()
                            ->helperText('Locks the account when the failed attempt threshold is reached (configured in Login Security).'),

                        TextInput::make('auto_unlock_after')
                            ->label('Auto Unlock After')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(10080)
                            ->required()
                            ->visible(fn ($get) => (bool) $get('disable_after_failed_attempts'))
                            ->suffix('minutes')
                            ->helperText('Set to 0 to require manual admin unlock.'),
                    ]),

                Section::make('Notifications')
                    ->description('Alert relevant parties when an account is locked.')
                    ->schema([
                        Toggle::make('notify_user')
                            ->label('Notify User')
                            ->helperText('Email the account owner when their account is locked.'),

                        Toggle::make('notify_admin')
                            ->label('Notify Admin')
                            ->helperText('Email the administrator when any account is locked.'),
                    ]),

                Section::make('Future Options')
                    ->description('Advanced threat-detection features — not yet available.')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('_future_suspicious_detection')
                                ->label('Suspicious Login Detection')
                                ->disabled()
                                ->helperText('Coming soon'),

                            Toggle::make('_future_ip_restriction')
                                ->label('IP Restrictions')
                                ->disabled()
                                ->helperText('Coming soon'),

                            Toggle::make('_future_device_restriction')
                                ->label('Device Restrictions')
                                ->disabled()
                                ->helperText('Coming soon'),
                        ]),
                    ]),

            ]),
        ]);
    }

    public function save(): void
    {
        Gate::authorize('security.account_protection.update');

        try {
            $data = $this->form->getState();
        } catch (Halt) {
            return;
        }

        if (! app(SecuritySettingsService::class)->saveAccountProtection($data)) {
            return;
        }

        Notification::make()->title('Account protection settings saved')->success()->send();
    }
}
