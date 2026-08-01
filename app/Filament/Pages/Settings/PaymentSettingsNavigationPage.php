<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Navigation\Concerns\HasSettingsSectionBreadcrumb;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as ActionsComponent;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class PaymentSettingsNavigationPage extends Page
{
    use HasCentralizedNavigation;
    use HasSettingsAccess;
    use HasSettingsSectionBreadcrumb;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Payment Settings';

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'payment-settings';

    protected static bool $shouldRegisterNavigation = false;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

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
        return 'Manage payment configuration from dedicated submenu pages.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Choose a payment settings section')
                ->description('Use the sidebar submenus under Payment Settings.')
                ->schema([
                    ActionsComponent::make([
                        Action::make('bank')
                            ->label('Bank Account')
                            ->url(PaymentBankAccountPage::getUrl()),
                        Action::make('gateways')
                            ->label('Payment Gateways')
                            ->url(PaymentGatewayPage::getUrl()),
                        Action::make('configuration')
                            ->label('Payment Configuration')
                            ->url(PaymentConfigurationPage::getUrl()),
                        Action::make('advanced')
                            ->label('Advanced')
                            ->url(PaymentAdvancedPage::getUrl()),
                    ]),
                ]),
        ]);
    }
}
