<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasFinancialReportFilters;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Finance\WalletRechargeMonitoringSummary;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Wallet\Enums\WalletRechargeStatus;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read-only wallet-recharge operational health across Razorpay,
 * Stripe, and Fake/local providers (SRS §13.33, GAP-003 closure). No
 * mutation action of any kind exists here — every state transition
 * stays owned by WalletRechargeService/WalletRechargeReconciliationService.
 * Requires `ViewWalletReports`.
 */
class RechargeMonitoring extends Page
{
    use HasFinancialReportFilters;

    protected string $view = 'filament.pages.recharge-monitoring';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $navigationLabel = 'Recharge Monitoring';

    protected static ?string $title = 'Recharge Monitoring';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 11;

    public string $rechargeStatus = '';

    public string $rechargeProvider = '';

    public string $rechargeReference = '';

    public bool $capturedUncreditedOnly = false;

    public bool $staleOnly = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $definition = app(ReportRegistryInterface::class)->find('recharge_monitoring');

        return $definition !== null && app(ReportAccessContextInterface::class)->canView($user, $definition);
    }

    public function summary(): WalletRechargeMonitoringSummary
    {
        return app(FinancialReportsServiceInterface::class)->rechargeMonitoringSummary(auth()->user());
    }

    public function rows(): LengthAwarePaginator
    {
        return app(FinancialReportsServiceInterface::class)->paginatedRechargeMonitoring(
            auth()->user(),
            [
                'status' => filled($this->rechargeStatus) ? $this->rechargeStatus : null,
                'provider' => filled($this->rechargeProvider) ? $this->rechargeProvider : null,
                'currencyCode' => filled($this->currencyCode) ? strtoupper($this->currencyCode) : null,
                'reference' => filled($this->rechargeReference) ? $this->rechargeReference : null,
                'capturedUncreditedOnly' => $this->capturedUncreditedOnly,
                'staleOnly' => $this->staleOnly,
            ],
            $this->period(),
            25,
        );
    }

    public function freshness(): OperationsReportFreshnessData
    {
        return app(FinancialReportsServiceInterface::class)->freshness($this->period());
    }

    /** @return Collection<int, WalletRechargeStatus> */
    public function statusOptions(): Collection
    {
        return collect(WalletRechargeStatus::cases());
    }

    /** @return list<string> */
    public function providerOptions(): array
    {
        return ['razorpay', 'stripe', 'fake'];
    }

    public function resetRechargeFilters(): void
    {
        $this->reset(['rechargeStatus', 'rechargeProvider', 'rechargeReference', 'capturedUncreditedOnly', 'staleOnly']);
        $this->resetFilters();
    }
}
