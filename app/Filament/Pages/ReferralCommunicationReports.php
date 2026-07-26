<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Reporting\Contracts\ReferralCommunicationReportServiceInterface;
use App\Reporting\DTOs\Communication\NotificationActivityData;
use App\Reporting\DTOs\Communication\ReferralActivityData;
use App\Reporting\DTOs\Communication\ReviewQualityRatesData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Referrals, Quality & Communications (SRS Chapters 16–19). Three
 * independently permission-gated sections delegated entirely to
 * {@see ReferralCommunicationReportServiceInterface}; this page holds
 * only filter state. A viewer sees exactly the sections their
 * permissions allow; the page is reachable when at least one section
 * is viewable.
 */
class ReferralCommunicationReports extends Page
{
    use HasCentralizedNavigation;

    protected string $view = 'filament.pages.referral-communication-reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Referrals & Communications';

    protected static ?string $title = 'Referrals, Quality & Communications';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 14;

    public string $periodPreset = 'last_30_days';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    // Livewire hydrates select values as strings — kept ?string, cast
    // once in filters() rather than typed here, to avoid a hydration
    // regression.
    public ?string $currencyCode = null;

    public ?string $instructorId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $service = app(ReferralCommunicationReportServiceInterface::class);

        return $service->canViewReferrals($user)
            || $service->canViewReviewQuality($user)
            || $service->canViewNotifications($user);
    }

    public function resetFilters(): void
    {
        $this->reset(['customStart', 'customEnd', 'currencyCode', 'instructorId']);
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

    private function filters(): ReportFilters
    {
        return new ReportFilters(
            period: $this->period(),
            currencyCode: filled($this->currencyCode) ? $this->currencyCode : null,
            instructorId: filled($this->instructorId) && is_numeric($this->instructorId) ? (int) $this->instructorId : null,
        );
    }

    public function referralActivity(): ?ReferralActivityData
    {
        $service = app(ReferralCommunicationReportServiceInterface::class);

        return $service->canViewReferrals(auth()->user())
            ? $service->referralActivity(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function reviewQualityRates(): ?ReviewQualityRatesData
    {
        $service = app(ReferralCommunicationReportServiceInterface::class);

        return $service->canViewReviewQuality(auth()->user())
            ? $service->reviewQualityRates(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function notificationActivity(): ?NotificationActivityData
    {
        $service = app(ReferralCommunicationReportServiceInterface::class);

        return $service->canViewNotifications(auth()->user())
            ? $service->notificationActivity(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function freshness(): OperationsReportFreshnessData
    {
        return app(ReferralCommunicationReportServiceInterface::class)->freshness($this->period());
    }

    public function qualityDashboardUrl(): ?string
    {
        return ReviewsQualityDashboard::canAccess() ? ReviewsQualityDashboard::getUrl() : null;
    }

    /** @return Collection<int, ReportingPeriodPreset> */
    public function periodPresets(): Collection
    {
        return collect(ReportingPeriodPreset::cases());
    }
}
