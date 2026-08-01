{{--
    Primary marketplace KPIs — at most six, capped by the composition
    service. A KPI the viewer may not see is absent from the list, so
    the grid closes up rather than showing a gap.

    Each card carries its domain's colour, so the same domain looks
    identical here, on its chart, in its summary and on its report tile.

    Two rules are visible:
      - A metric whose owning calculation returned null renders an em
        dash with the reason, never "0%", and is visually distinct from
        a real zero without looking broken.
      - A sparkline appears only where an authoritative daily series
        already exists (student registrations). None is synthesised, and
        no "vs. previous period" delta is shown anywhere, because no
        previous-period mechanism exists in the reporting layer.
--}}
@if (count($dashboard->kpis) > 0)
    <section aria-labelledby="dashboard-kpi-heading">

        @include('filament.pages.partials.dashboard._section-heading', [
            'id' => 'dashboard-kpi-heading',
            'eyebrow' => 'Key indicators',
            'title' => 'Marketplace at a glance',
            'purpose' => 'Headline figures for the selected period.',
            'icon' => 'heroicon-m-sparkles',
            'domain' => 'dash-d-brand',
        ])

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($dashboard->kpis as $kpi)
                @php
                    $tag = $kpi->url !== null ? 'a' : 'div';
                    $domain = \App\Dashboard\Support\DashboardPalette::domainClass($kpi->key);
                @endphp

                <{{ $tag }}
                    @if ($kpi->url !== null) href="{{ $kpi->url }}" @endif
                    class="{{ $domain }} dash-card dash-wash group gap-3 p-4"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="break-words text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $kpi->label }}
                            </p>

                            <p @class([
                                'mt-1.5 text-3xl font-bold leading-none tracking-tight tabular-nums',
                                'text-gray-950 dark:text-white' => ! $kpi->isUnavailable,
                                // Not calculable reads as deliberately muted,
                                // never as a broken or failed card.
                                'text-gray-400 dark:text-gray-500' => $kpi->isUnavailable,
                            ])>
                                {{ $kpi->value }}
                            </p>

                            <p class="mt-1 inline-flex items-center gap-1 text-[11px] text-gray-500 dark:text-gray-400">
                                <x-filament::icon
                                    :icon="$kpi->contextLabel === 'As of now' ? 'heroicon-m-clock' : 'heroicon-m-calendar-days'"
                                    class="h-3 w-3 shrink-0"
                                />
                                {{ $kpi->contextLabel }}
                            </p>
                        </div>

                        <span class="dash-capsule h-10 w-10 shrink-0">
                            <x-filament::icon :icon="$kpi->icon" class="h-5 w-5" />
                        </span>
                    </div>

                    @if ($kpi->hasSparkline())
                        {{-- Inline sparkline: an authoritative daily series
                             rendered without a chart library. Decorative
                             only in the sense that the report owns the
                             detail — the shape itself is real data. --}}
                        @php
                            $points = $kpi->sparkline;
                            $max = max(1, max($points));
                            $steps = max(1, count($points) - 1);
                            $coords = collect($points)
                                ->map(fn (int|float $value, int $index): string => sprintf(
                                    '%.2f,%.2f',
                                    $index / $steps * 100,
                                    30 - ($value / $max * 26),
                                ))
                                ->implode(' ');
                            $areaId = 'spark-'.$kpi->key;
                        @endphp
                        <svg viewBox="0 0 100 30" preserveAspectRatio="none" class="h-8 w-full" role="img"
                             aria-label="Daily trend for {{ $kpi->label }} across the selected period.">
                            <defs>
                                <linearGradient id="{{ $areaId }}" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="var(--a)" stop-opacity="0.35" />
                                    <stop offset="100%" stop-color="var(--a)" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            <polygon points="0,30 {{ $coords }} 100,30" fill="url(#{{ $areaId }})" />
                            <polyline points="{{ $coords }}" fill="none" stroke="var(--a)" stroke-width="1.5"
                                      stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                        </svg>
                    @endif

                    <div class="mt-auto">
                        @if ($kpi->isUnavailable && $kpi->unavailableReason !== null)
                            <p class="flex items-start gap-1.5 text-[11px] leading-snug text-gray-500 dark:text-gray-400">
                                <x-filament::icon icon="heroicon-m-information-circle" class="mt-px h-3 w-3 shrink-0" />
                                <span>{{ $kpi->unavailableReason }}</span>
                            </p>
                        @else
                            <p class="text-[11px] leading-snug text-gray-500 dark:text-gray-400">
                                {{ $kpi->definition }}
                            </p>
                        @endif

                        @if ($kpi->url !== null)
                            <p class="mt-2 inline-flex items-center gap-1 border-t border-gray-950/5 pt-2 text-[11px] font-semibold text-gray-700 group-hover:underline dark:border-white/5 dark:text-gray-200">
                                Open report
                                <x-filament::icon icon="heroicon-m-arrow-right" class="dash-go h-3 w-3 shrink-0" />
                            </p>
                        @endif
                    </div>
                </{{ $tag }}>
            @endforeach
        </div>
    </section>
@endif
