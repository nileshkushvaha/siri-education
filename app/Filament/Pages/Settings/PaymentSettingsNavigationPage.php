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

    protected static ?string $navigationLabel = 'Finance Settings';

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'payment-settings';

    public static function getLabel(): string
    {
        return 'Finance Settings';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Finance Settings';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Manage student payment collection, instructor earning rules, and payout-provider configuration.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Choose a finance settings section')
                ->description('These settings affect how money is collected, recorded, and paid out. Change advanced or provider settings only during a controlled configuration review.')
                ->schema([
                    ActionsComponent::make([
                        Action::make('bank')
                            ->label('Bank Account')
                            ->icon(Heroicon::OutlinedBuildingLibrary)
                            ->url(PaymentBankAccountPage::getUrl())
                            ->visible(PaymentBankAccountPage::canAccess()),
                        Action::make('gateways')
                            ->label('Payment Gateways')
                            ->icon(Heroicon::OutlinedCreditCard)
                            ->url(PaymentGatewayPage::getUrl())
                            ->visible(PaymentGatewayPage::canAccess()),
                        Action::make('configuration')
                            ->label('Payment Configuration')
                            ->icon(Heroicon::OutlinedCog8Tooth)
                            ->url(PaymentConfigurationPage::getUrl())
                            ->visible(PaymentConfigurationPage::canAccess()),
                        Action::make('advanced')
                            ->label('Advanced Finance Settings')
                            ->icon(Heroicon::OutlinedWrenchScrewdriver)
                            ->url(PaymentAdvancedPage::getUrl())
                            ->visible(PaymentAdvancedPage::canAccess()),
                        Action::make('earningRules')
                            ->label('Instructor Earnings Rules')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->url(InstructorEarningSettingsPage::getUrl())
                            ->visible(InstructorEarningSettingsPage::canAccess()),
                        Action::make('razorpayXPayouts')
                            ->label('RazorpayX Payout Settings')
                            ->icon(Heroicon::OutlinedBuildingOffice2)
                            ->url(RazorpayXPayoutSettingsPage::getUrl())
                            ->visible(RazorpayXPayoutSettingsPage::canAccess()),
                    ]),
                ]),
        ]);
    }
}
