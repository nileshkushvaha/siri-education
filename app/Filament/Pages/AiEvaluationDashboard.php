<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Reporting\Contracts\AiEvaluationReportServiceInterface;
use App\Reporting\DTOs\Ai\AiEvaluationOverviewData;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Support\ReportPeriodResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * AI evaluation: whether the four AI features are useful, accurate,
 * used, and worth their cost.
 *
 * Composition only — every figure is produced by
 * AiEvaluationReportService from ai_runs, each feature's own outcome
 * record, and reviewer verdicts. This page calculates nothing and
 * writes nothing.
 *
 * Gated on `Configure:AiPlatform`: whoever operates the AI platform and
 * holds its budget is exactly who should see whether it is working. No
 * new permission was created for this page.
 */
class AiEvaluationDashboard extends Page
{
    use HasCentralizedNavigation;

    protected string $view = 'filament.pages.ai-evaluation-dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'AI Evaluation';

    protected static ?string $title = 'AI Evaluation';

    protected static string|\UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $slug = 'reports/ai-evaluation';

    #[Url(as: 'period')]
    public string $periodPreset = 'last_30_days';

    #[Url(as: 'start')]
    public ?string $customStart = null;

    #[Url(as: 'end')]
    public ?string $customEnd = null;

    public static function canAccess(): bool
    {
        return app(AiEvaluationReportServiceInterface::class)->canView(auth()->user());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Usage, cost, human acceptance and prompt performance across every AI feature. Advisory reporting — nothing here changes AI behaviour.';
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

    public function overview(): ?AiEvaluationOverviewData
    {
        $user = auth()->user();

        return $user === null
            ? null
            : app(AiEvaluationReportServiceInterface::class)->overview($user, $this->period());
    }

    public function resetFilters(): void
    {
        $this->reset(['customStart', 'customEnd']);
        $this->periodPreset = 'last_30_days';
    }

    /** @return Collection<int, ReportingPeriodPreset> */
    public function periodPresets(): Collection
    {
        return collect(ReportingPeriodPreset::cases());
    }

    /** Percentage display that keeps "no data yet" distinct from "zero percent". */
    public function percent(?float $ratio): string
    {
        return $ratio === null ? 'No data yet' : round($ratio * 100).'%';
    }

    public function money(?float $amount, string $currency): string
    {
        return $amount === null ? '—' : number_format($amount, 4).' '.$currency;
    }
}
