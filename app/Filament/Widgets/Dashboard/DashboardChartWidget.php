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

    /**
     * The dashboard card that hosts this widget already renders the
     * chart's title, subtitle and header totals, so the widget's own
     * heading is suppressed to avoid printing both.
     *
     * `chart()?->title` remains the accessible name via the canvas
     * `aria-label` below.
     */
    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getDescription(): string|Htmlable|null
    {
        return null;
    }

    /**
     * A plain-language summary of the series, so a reader who cannot
     * perceive the chart still gets its content.
     */
    public function chartSummary(): string
    {
        $chart = $this->chart();

        if ($chart === null) {
            return '';
        }

        $parts = [];

        foreach ($chart->segments as $segment) {
            $parts[] = sprintf('%s: %s', $segment['label'], number_format((int) $segment['value']));
        }

        foreach ($chart->datasets as $dataset) {
            if ($chart->segments !== []) {
                break;
            }

            $parts[] = sprintf('%s totalling %s', $dataset['label'], number_format(array_sum($dataset['data'])));
        }

        return sprintf('%s. %s.', $chart->title, implode('; ', $parts));
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
     * Shared chart chrome: grid, tooltip, legend and axis treatment used
     * by every dashboard chart so they read as one system.
     *
     * Grid lines are a low-alpha slate rather than white — a pure-white
     * rule on a dark plot competes with the data. Tick density is capped
     * with `autoSkip` + `maxTicksLimit` so a 30-day range never produces
     * the unreadable diagonal date wall it otherwise would; labels stay
     * horizontal for the same reason.
     *
     * @return array<string, mixed>
     */
    protected function baseOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'layout' => ['padding' => ['top' => 4, 'right' => 4, 'bottom' => 0, 'left' => 0]],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                    'labels' => [
                        'usePointStyle' => true,
                        'pointStyle' => 'circle',
                        'boxWidth' => 7,
                        'boxHeight' => 7,
                        'padding' => 14,
                        'font' => ['size' => 11],
                    ],
                ],
                'tooltip' => $this->tooltipOptions(),
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'border' => ['display' => false],
                    'ticks' => ['precision' => 0, 'maxTicksLimit' => 5, 'font' => ['size' => 10], 'padding' => 6],
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.16)', 'drawTicks' => false],
                ],
                'x' => [
                    'border' => ['display' => false],
                    'grid' => ['display' => false],
                    'ticks' => [
                        'autoSkip' => true,
                        // Keeps date labels horizontal and sparse enough
                        // to stay legible at every breakpoint.
                        'maxTicksLimit' => 7,
                        'maxRotation' => 0,
                        'minRotation' => 0,
                        'font' => ['size' => 10],
                        'padding' => 4,
                    ],
                ],
            ],
        ];
    }

    /**
     * Compact, high-contrast tooltip shared by every chart.
     *
     * @return array<string, mixed>
     */
    protected function tooltipOptions(): array
    {
        return [
            'backgroundColor' => 'rgba(15, 20, 32, 0.96)',
            'titleColor' => '#f8fafc',
            'bodyColor' => '#cbd5e1',
            'borderColor' => 'rgba(148, 163, 184, 0.25)',
            'borderWidth' => 1,
            'cornerRadius' => 8,
            'padding' => 10,
            'displayColors' => true,
            'usePointStyle' => true,
            'boxPadding' => 4,
            'titleFont' => ['size' => 12, 'weight' => '600'],
            'bodyFont' => ['size' => 11],
        ];
    }

    /**
     * Translucent area fill for line charts.
     *
     * A true canvas gradient would need `getOptions()` to return the
     * whole options object as `RawJs`, since Filament JSON-encodes an
     * array and a nested JS callback would not survive that. A flat
     * low-alpha wash reads almost identically under the plot and keeps
     * the options declarative, so the gradient work is done where it is
     * genuinely free: the CSS surfaces and the SVG KPI sparklines.
     */
    protected function areaFill(string $hex, string $alpha = '26'): string
    {
        return $hex.$alpha;
    }
}
