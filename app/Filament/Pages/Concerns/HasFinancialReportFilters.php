<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Wallet\Enums\WalletLedgerEntryType;
use Illuminate\Support\Collection;

/**
 * Phase 18E — shared filter state for the four financial report pages.
 * Livewire hydrates select values as strings (Phase 18C lesson) — all
 * properties stay ?string and are cast once in filters().
 */
trait HasFinancialReportFilters
{
    public string $periodPreset = 'last_30_days';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    public ?string $currencyCode = null;

    public ?string $instructorId = null;

    public ?string $walletTransactionType = null;

    public function resetFilters(): void
    {
        $this->reset(['customStart', 'customEnd', 'currencyCode', 'instructorId', 'walletTransactionType']);
        $this->periodPreset = 'last_30_days';
    }

    public function period(): ReportingPeriod
    {
        $preset = ReportingPeriodPreset::tryFrom($this->periodPreset) ?? ReportingPeriodPreset::Last30Days;
        $timezone = ReportingTimezoneResolver::resolve();

        if ($preset === ReportingPeriodPreset::Custom && filled($this->customStart) && filled($this->customEnd)) {
            return ReportingPeriod::custom($this->customStart, $this->customEnd, $timezone);
        }

        return ReportingPeriod::forPreset($preset === ReportingPeriodPreset::Custom ? ReportingPeriodPreset::Last30Days : $preset, $timezone);
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
