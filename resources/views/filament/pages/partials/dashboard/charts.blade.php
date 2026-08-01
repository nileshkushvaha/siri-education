{{--
    Core charts — the dashboard's primary analytical content.

    Each chart is a Livewire widget that receives the global context as
    props and reads the already-composed (and cached) dashboard, so
    several lazily-loaded charts share one computation rather than each
    re-running the owning report services.

    Charts are keyed on the context hash so a period or country change
    re-mounts them instead of leaving a previous period's series on
    screen.

    Layout rules that matter:
      - The outcome composition spans the full width.
      - Supply & demand spans full width when it would otherwise be
        stranded alone on its row.
      - An empty chart collapses to a compact empty state; it never
        leaves a chart-sized blank rectangle.
--}}
@php
    // Chart key => widget class. A chart absent from the composition
    // (permission missing, or no data source) simply never appears.
    $widgets = [
        'lesson_outcomes' => \App\Filament\Widgets\Dashboard\LessonOutcomeChartWidget::class,
        'student_registrations' => \App\Filament\Widgets\Dashboard\StudentRegistrationChartWidget::class,
        'bookings_per_day' => \App\Filament\Widgets\Dashboard\BookingsPerDayChartWidget::class,
        'supply_demand' => \App\Filament\Widgets\Dashboard\SupplyDemandChartWidget::class,
    ];

    $emptyCopy = [
        'lesson_outcomes' => ['icon' => 'heroicon-o-check-badge', 'tone' => 'no-activity'],
        'student_registrations' => ['icon' => 'heroicon-o-user-plus', 'tone' => 'no-activity'],
        'bookings_per_day' => ['icon' => 'heroicon-o-calendar-days', 'tone' => 'no-activity'],
        'supply_demand' => ['icon' => 'heroicon-o-globe-alt', 'tone' => 'unavailable'],
    ];

    $primaryCharts = collect($dashboard->charts)
        ->filter(fn ($chart) => array_key_exists($chart->key, $widgets))
        ->values();

    // Half-width charts are the ones that pair up; if an odd number of
    // them exists the last is promoted to full width so it is never
    // stranded as a half-width orphan.
    $pairable = $primaryCharts->reject(fn ($c) => $c->key === 'lesson_outcomes')->values();
    $orphanKey = $pairable->count() % 2 === 1 ? $pairable->last()->key : null;
@endphp

@if ($primaryCharts->isNotEmpty())
    <section aria-labelledby="dashboard-charts-heading">

        @include('filament.pages.partials.dashboard._section-heading', [
            'id' => 'dashboard-charts-heading',
            'eyebrow' => 'Analytics',
            'title' => 'Marketplace performance',
            'purpose' => 'Activity during the selected period.',
            'icon' => 'heroicon-m-chart-bar-square',
            'domain' => 'dash-d-ops',
        ])

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ($primaryCharts as $chart)
                @php
                    $domain = \App\Dashboard\Support\DashboardPalette::domainClass($chart->key);
                    $full = $chart->key === 'lesson_outcomes' || $chart->key === $orphanKey;
                    $meta = $emptyCopy[$chart->key] ?? ['icon' => 'heroicon-o-chart-bar', 'tone' => 'no-activity'];

                    // Header totals, derived in the view from data the
                    // composition already returned — no payload change.
                    $segmentTotal = collect($chart->segments)->sum('value');
                    $seriesTotals = collect($chart->datasets)
                        ->map(fn (array $d): array => ['label' => $d['label'], 'total' => array_sum($d['data'])]);
                @endphp

                <div @class([$domain, 'dash-card dash-wash min-w-0 p-4', 'xl:col-span-2' => $full])>

                    {{-- Chart header: title, purpose and a real total. --}}
                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1.5">
                        <div class="min-w-0">
                            <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                                <span class="dash-capsule h-6 w-6 shrink-0">
                                    <x-filament::icon :icon="$meta['icon']" class="h-3 w-3" />
                                </span>
                                {{ $chart->title }}
                            </h3>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $chart->subtitle }}</p>
                        </div>

                        @if (! $chart->isEmpty())
                            <div class="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-1">
                                @if ($chart->segments !== [])
                                    <p class="text-right">
                                        <span class="block text-lg font-bold leading-none tabular-nums text-gray-950 dark:text-white">
                                            {{ number_format($segmentTotal) }}
                                        </span>
                                        <span class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">finalized</span>
                                    </p>
                                @else
                                    @foreach ($seriesTotals as $series)
                                        <p class="text-right">
                                            <span class="block text-lg font-bold leading-none tabular-nums text-gray-950 dark:text-white">
                                                {{ number_format($series['total']) }}
                                            </span>
                                            <span class="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $series['label'] }}</span>
                                        </p>
                                    @endforeach
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($chart->isEmpty())
                        <div class="mt-3">
                            @include('filament.pages.partials.dashboard._empty-state', [
                                'message' => $chart->emptyMessage ?? 'No data for this period.',
                                'icon' => $meta['icon'],
                                'tone' => $meta['tone'],
                                'domain' => $domain,
                                'url' => $chart->url,
                                'linkLabel' => 'Open the owning report',
                            ])
                        </div>
                    @else
                        <div class="dash-plot mt-3 min-w-0 p-3">
                            @livewire($widgets[$chart->key], $chartProps, key($chart->key.'-'.$chartKey))
                        </div>
                    @endif

                    {{-- Accessible legend for the stacked composition:
                         label, count, percentage and its own destination,
                         so meaning never depends on colour alone. --}}
                    @if ($chart->segments !== [] && ! $chart->isEmpty())
                        <ul class="mt-3 grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($chart->segments as $segment)
                                <li>
                                    <a
                                        href="{{ $segment['url'] }}"
                                        class="group/seg flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-xs transition-colors hover:bg-gray-950/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:hover:bg-white/5"
                                    >
                                        <span class="flex min-w-0 items-center gap-2">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $segment['color'] }}"></span>
                                            <span class="truncate text-gray-700 group-hover/seg:underline dark:text-gray-300">{{ $segment['label'] }}</span>
                                        </span>
                                        <span class="shrink-0 tabular-nums text-gray-950 dark:text-white">
                                            {{ number_format($segment['value']) }}@if ($segment['percentage'] !== null)<span class="ml-1 font-normal text-gray-500 dark:text-gray-400">{{ $segment['percentage'] }}%</span>@endif
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($chart->url !== null && ! $chart->isEmpty())
                        <a
                            href="{{ $chart->url }}"
                            class="group mt-3 inline-flex items-center gap-1 self-start rounded text-xs font-semibold text-gray-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-200"
                        >
                            Open the owning report
                            <x-filament::icon icon="heroicon-m-arrow-right" class="dash-go h-3 w-3 shrink-0" />
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
