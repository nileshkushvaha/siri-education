{{--
    Primary marketplace KPIs — at most six, capped by the composition
    service. A KPI the viewer may not see is absent from the list, so
    the grid closes up rather than showing a gap.

    Two rules are visible here:
      - A metric whose owning calculation returned null renders an em
        dash with the reason, never "0%".
      - A sparkline appears only where an authoritative daily series
        already exists (student registrations). None is synthesised, and
        no "vs. previous period" delta is shown anywhere, because no
        previous-period mechanism exists in the reporting layer.
--}}
@if (count($dashboard->kpis) > 0)
    <section aria-labelledby="dashboard-kpi-heading">
        <h2 id="dashboard-kpi-heading" class="sr-only">Marketplace key performance indicators</h2>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($dashboard->kpis as $kpi)
                @php $tag = $kpi->url !== null ? 'a' : 'div'; @endphp

                <{{ $tag }}
                    @if ($kpi->url !== null) href="{{ $kpi->url }}" @endif
                    class="group flex h-full flex-col justify-between gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition-colors duration-150 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:bg-gray-900 dark:ring-white/10 dark:hover:bg-gray-800/60"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="break-words text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $kpi->label }}
                            </p>
                            <p class="mt-1 text-2xl font-bold tabular-nums text-gray-950 dark:text-white">
                                {{ $kpi->value }}
                            </p>
                            <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                                {{ $kpi->contextLabel }}
                            </p>
                        </div>

                        <span class="shrink-0 rounded-lg bg-gray-100 p-2 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <x-filament::icon :icon="$kpi->icon" class="h-5 w-5" />
                        </span>
                    </div>

                    @if ($kpi->hasSparkline())
                        {{-- Inline sparkline: no chart library needed for a
                             decorative-free 30-point series, and it stays
                             legible in both themes. --}}
                        @php
                            $points = $kpi->sparkline;
                            $max = max(1, max($points));
                            $count = max(1, count($points) - 1);
                            $path = collect($points)
                                ->map(fn (int|float $value, int $index): string => sprintf(
                                    '%.2f,%.2f',
                                    $index / $count * 100,
                                    28 - ($value / $max * 24),
                                ))
                                ->implode(' ');
                        @endphp
                        <svg viewBox="0 0 100 28" preserveAspectRatio="none" class="h-7 w-full" aria-hidden="true" focusable="false">
                            <polyline
                                points="{{ $path }}"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                vector-effect="non-scaling-stroke"
                                class="text-primary-500 dark:text-primary-400"
                            />
                        </svg>
                    @endif

                    <div>
                        @if ($kpi->isUnavailable && $kpi->unavailableReason !== null)
                            <p class="text-[11px] leading-snug text-warning-700 dark:text-warning-400">
                                {{ $kpi->unavailableReason }}
                            </p>
                        @else
                            <p class="text-[11px] leading-snug text-gray-500 dark:text-gray-400">
                                {{ $kpi->definition }}
                            </p>
                        @endif

                        @if ($kpi->url !== null)
                            <p class="mt-1.5 inline-flex items-center gap-1 text-[11px] font-medium text-primary-600 group-hover:underline dark:text-primary-400">
                                Open report
                                <x-filament::icon icon="heroicon-m-arrow-right" class="h-3 w-3 shrink-0" />
                            </p>
                        @endif
                    </div>
                </{{ $tag }}>
            @endforeach
        </div>
    </section>
@endif
