<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\ReportPeriodResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Wallet\Enums\WalletLedgerEntryType;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Shared filter state for the four financial report pages.
 * Livewire hydrates select values as strings — all
 * properties stay ?string and are cast once in filters().
 *
 * Every filter is bound to the query string so the dashboard can hand
 * its period and currency context straight to a financial report, and
 * so a browser back-navigation restores the previous view. Only the
 * dimensions these four pages actually implement are bound — a report
 * never accepts a filter it does not support.
 *
 * Raw URL values are untrusted: the period goes through
 * {@see ReportPeriodResolver} (which enforces `ReportingPeriod`'s
 * maximum-range and ordering rules and degrades to the default rather
 * than throwing), and every other value is either `tryFrom()`-cast to a
 * valid enum case or dropped in filters().
 */
trait HasFinancialReportFilters
{
    #[Url(as: 'period')]
    public string $periodPreset = 'last_30_days';

    #[Url(as: 'start')]
    public ?string $customStart = null;

    #[Url(as: 'end')]
    public ?string $customEnd = null;

    #[Url(as: 'currency')]
    public ?string $currencyCode = null;

    #[Url(as: 'instructor')]
    public ?string $instructorId = null;

    #[Url(as: 'wallet_transaction_type')]
    public ?string $walletTransactionType = null;

    public function resetFilters(): void
    {
        $this->reset(['customStart', 'customEnd', 'currencyCode', 'instructorId', 'walletTransactionType']);
        $this->periodPreset = 'last_30_days';
    }

    public function period(): ReportingPeriod
    {
        return ReportPeriodResolver::resolve(
            preset: $this->periodPreset,
            customStart: $this->customStart,
            customEnd: $this->customEnd,
            default: ReportingPeriodPreset::Last30Days,
        );
    }

    protected function filters(): ReportFilters
    {
        return new ReportFilters(
            period: $this->period(),
            currencyCode: filled($this->currencyCode) ? strtoupper($this->currencyCode) : null,
            instructorId: filled($this->instructorId) && is_numeric($this->instructorId) ? (int) $this->instructorId : null,
            walletTransactionType: filled($this->walletTransactionType) ? WalletLedgerEntryType::tryFrom($this->walletTransactionType) : null,
        );
    }

    /** @return Collection<int, ReportingPeriodPreset> */
    public function periodPresets(): Collection
    {
        return collect(ReportingPeriodPreset::cases());
    }
}
