{{--
    Core charts. At most four render here; the optional homework chart
    lives inside the learning summary section instead.

    Each chart is a Livewire widget that receives the global context as
    props and reads the already-composed (and cached) dashboard, so
    several lazily-loaded charts share one computation rather than each
    re-running the owning report services.

    Charts are keyed on the context hash so a period or country change
    re-mounts them instead of leaving a previous period's series on
    screen.
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

    $primaryCharts = collect($dashboard->charts)
        ->filter(fn ($chart) => array_key_exists($chart->key, $widgets))
        ->values();
@endphp

@if ($primaryCharts->isNotEmpty())
    <section aria-labelledby="dashboard-charts-heading" class="space-y-4">
        <h2 id="dashboard-charts-heading" class="text-base font-semibold text-gray-950 dark:text-white">
            Marketplace performance
        </h2>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ($primaryCharts as $chart)
                @php
                    // The outcome composition reads best full-width; the
                    // rest pair up on wide screens.
                    $isFullWidth = $chart->key === 'lesson_outcomes';
                @endphp

                <div @class([
                    'fi-section flex min-w-0 flex-col rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10',
                    'xl:col-span-2' => $isFullWidth,
                ])>
                    @if ($chart->isEmpty())
                        <div class="flex flex-col">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $chart->title }}</p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $chart->subtitle }}</p>
                            <div class="mt-4 flex flex-col items-center justify-center rounded-lg bg-gray-50 py-8 text-center dark:bg-white/5">
                                <x-filament::icon icon="heroicon-o-chart-bar" class="h-6 w-6 text-gray-400 dark:text-gray-500" />
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $chart->emptyMessage ?? 'No data for this period.' }}
                                </p>
                            </div>
                        </div>
                    @else
                        <div class="min-w-0">
                            @livewire(
                                $widgets[$chart->key],
                                $chartProps,
                                key($chart->key . '-' . $chartKey)
                            )
                        </div>
                    @endif

                    {{-- Accessible legend for the stacked composition:
                         label, count, percentage and its own destination,
                         so meaning never depends on colour alone. --}}
                    @if ($chart->segments !== [])
                        <ul class="mt-3 grid grid-cols-1 gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($chart->segments as $segment)
                                <li>
                                    <a
                                        href="{{ $segment['url'] }}"
                                        class="group flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-xs transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:hover:bg-white/5"
                                    >
                                        <span class="flex min-w-0 items-center gap-2">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-sm" style="background-color: {{ $segment['color'] }}"></span>
                                            <span class="truncate text-gray-700 group-hover:underline dark:text-gray-300">{{ $segment['label'] }}</span>
                                        </span>
                                        <span class="shrink-0 tabular-nums text-gray-950 dark:text-white">
                                            {{ number_format($segment['value']) }}@if ($segment['percentage'] !== null)<span class="ml-1 text-gray-500 dark:text-gray-400">({{ $segment['percentage'] }}%)</span>@endif
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($chart->url !== null)
                        <a
                            href="{{ $chart->url }}"
                            class="mt-3 inline-flex items-center gap-1 self-start rounded-lg text-xs font-medium text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400"
                        >
                            Open the owning report
                            <x-filament::icon icon="heroicon-m-arrow-right" class="h-3 w-3 shrink-0" />
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
