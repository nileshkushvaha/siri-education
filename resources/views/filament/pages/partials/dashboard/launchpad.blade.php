{{--
    Report launchpad.

    Driven entirely by `ReportRegistryInterface::availableFor($user)`,
    further filtered by each destination page's own `canAccess()` — the
    same mechanism the Reporting Hub uses — so a newly registered report
    appears here automatically, and an unavailable, unpermitted or
    would-403 report can never appear at all. Nothing is hardcoded.

    Six primary links; the remainder collapse behind a proper expandable
    action, and the Reporting Hub remains the complete destination.
--}}
@if (count($dashboard->primaryReports) > 0)
    <section aria-labelledby="dashboard-reports-heading">

        @include('filament.pages.partials.dashboard._section-heading', [
            'id' => 'dashboard-reports-heading',
            'eyebrow' => 'Reporting',
            'title' => 'Reports',
            'purpose' => 'Explore detailed analysis.',
            'icon' => 'heroicon-m-document-chart-bar',
            'domain' => 'dash-d-brand',
            'actionUrl' => $dashboard->reportingHubUrl,
            'actionLabel' => $dashboard->reportingHubUrl !== null ? 'View all reports' : null,
        ])

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($dashboard->primaryReports as $report)
                <a
                    href="{{ $report->url }}"
                    class="{{ \App\Dashboard\Support\DashboardPalette::domainClass($report->key) }} dash-card dash-card-row dash-wash group items-start gap-3 p-4"
                >
                    <span class="dash-capsule h-9 w-9 shrink-0">
                        <x-filament::icon :icon="$report->icon" class="h-4 w-4" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block text-[10px] font-semibold uppercase tracking-wide" style="color: var(--a)">
                            {{ $report->category->label() }}
                        </span>
                        <span class="mt-0.5 block break-words text-sm font-semibold text-gray-950 group-hover:underline dark:text-white">
                            {{ $report->label }}
                        </span>
                        <span class="mt-1 line-clamp-2 block text-xs leading-snug text-gray-500 dark:text-gray-400">
                            {{ $report->description }}
                        </span>
                    </span>

                    <x-filament::icon
                        icon="heroicon-m-arrow-right"
                        class="dash-go mt-0.5 h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500"
                    />
                </a>
            @endforeach
        </div>

        @if (count($dashboard->additionalReports) > 0)
            <details class="group mt-3">
                <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-lg border border-gray-950/10 px-3 py-1.5 text-xs font-semibold text-gray-700 transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5">
                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-180 motion-reduce:transition-none" />
                    Show {{ count($dashboard->additionalReports) }} more report{{ count($dashboard->additionalReports) === 1 ? '' : 's' }}
                </summary>

                <ul class="mt-2 grid grid-cols-1 gap-1.5 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($dashboard->additionalReports as $report)
                        <li>
                            <a
                                href="{{ $report->url }}"
                                class="{{ \App\Dashboard\Support\DashboardPalette::domainClass($report->key) }} dash-card dash-card-row dash-card-interactive items-center gap-2.5 px-3 py-2 text-sm"
                            >
                                <x-filament::icon :icon="$report->icon" class="h-4 w-4 shrink-0" style="color: var(--a)" />
                                <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ $report->label }}</span>
                                <x-filament::icon icon="heroicon-m-arrow-right" class="dash-go h-3 w-3 shrink-0 text-gray-400 dark:text-gray-500" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </section>
@endif
