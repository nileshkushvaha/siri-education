<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Dashboard;

use App\Dashboard\DTOs\DashboardChart;
use App\Dashboard\DTOs\DashboardContext;
use App\Dashboard\Services\DashboardCompositionService;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Support\ReportPeriodResolver;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Shared behaviour for the dashboard's charts.
 *
 * A chart widget never queries. It receives the dashboard's global
 * filter context as Livewire properties, asks
 * {@see DashboardCompositionService} for the already-composed
 * dashboard, and renders the one {@see DashboardChart} matching its
 * key. Because that composition is cached per user, per permission
 * signature and per resolved period, several lazily-loaded chart
 * widgets on one page share a single computation rather than each
 * re-running the owning report services.
 *
 * A chart whose data the viewer may not see is simply absent from the
 * composition, so `canView()` returning false here means the section
 * was never built — not that it was built and hidden.
 */
abstract class DashboardChartWidget extends ChartWidget
{
    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    /**
     * Filament widgets are lazy by default and render
     * `filament::components.loading-section` while they load. Pinning
     * the placeholder to the chart's own height keeps the skeleton the
     * same shape as the finished chart, so the section does not jump
     * once data arrives.
     */
    protected ?string $placeholderHeight = '260px';

    /** Global context, passed down from the dashboard page. */
    public string $periodPreset = 'last_30_days';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    public ?string $countryId = null;

    /** Matches `DashboardChart::$key` produced by the composition service. */
    abstract protected function chartKey(): string;

    public function getHeading(): string|Htmlable|null
    {
        return $this->chart()?->title;
    }

    public function getDescription(): string|Htmlable|null
    {
        return $this->chart()?->subtitle;
    }

    protected function chart(): ?DashboardChart
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        foreach (app(DashboardCompositionService::class)->compose($user, $this->context())->charts as $chart) {
            if ($chart->key === $this->chartKey()) {
                return $chart;
            }
        }

        return null;
    }

    protected function context(): DashboardContext
    {
        return new DashboardContext(
            period: ReportPeriodResolver::resolve(
                preset: $this->periodPreset,
                customStart: $this->customStart,
                customEnd: $this->customEnd,
                default: ReportingPeriodPreset::Last30Days,
            ),
            countryId: filled($this->countryId) && is_numeric($this->countryId) ? (int) $this->countryId : null,
        );
    }

    /**
     * Chart.js dataset shaping shared by the line/bar charts. Colour is
     * carried on the DTO so the composition service — not the view —
     * owns the semantic mapping (e.g. which colour means "no-show").
     *
     * @return array{datasets: list<array<string, mixed>>, labels: list<string>}
     */
    protected function getData(): array
    {
        $chart = $this->chart();

        if ($chart === null) {
            return ['datasets' => [], 'labels' => []];
        }

        return [
            'datasets' => array_map(
                fn (array $dataset): array => $this->dataset($dataset),
                $chart->datasets,
            ),
            'labels' => $chart->labels,
        ];
    }

    /**
     * @param  array{label: string, data: list<int|float>, color: string}  $dataset
     * @return array<string, mixed>
     */
    abstract protected function dataset(array $dataset): array;

    /**
     * Dark-theme-legible grid, legend and tooltip defaults. Filament's
     * chart component re-themes automatically, so only the parts Chart.js
     * does not adapt (grid alpha, tick precision) are set here.
     *
     * @return array<string, mixed>
     */
    protected function baseOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                    'labels' => ['usePointStyle' => true, 'boxWidth' => 8],
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.18)'],
                ],
                'x' => [
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }
}
