{{--
    Report launchpad.

    Driven entirely by `ReportRegistryInterface::availableFor($user)` —
    the same mechanism the Reporting Hub uses — so a newly registered
    report appears here automatically, and an unavailable or unpermitted
    one can never appear at all. Nothing is hardcoded.

    Six primary links; the remainder collapse behind a disclosure, and
    the Reporting Hub remains the complete destination.
--}}
@if (count($dashboard->primaryReports) > 0)
    <section aria-labelledby="dashboard-reports-heading">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 id="dashboard-reports-heading" class="text-base font-semibold text-gray-950 dark:text-white">
                Reports
            </h2>

            @if ($dashboard->reportingHubUrl !== null)
                <a
                    href="{{ $dashboard->reportingHubUrl }}"
                    class="rounded-lg px-2 py-1 text-xs font-semibold text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400"
                >
                    View all reports
                </a>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($dashboard->primaryReports as $report)
                <a
                    href="{{ $report->url }}"
                    class="group flex h-full items-start gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition-colors duration-150 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:bg-gray-900 dark:ring-white/10 dark:hover:bg-gray-800/60"
                >
                    <span class="shrink-0 rounded-lg bg-primary-50 p-2 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                        <x-filament::icon :icon="$report->icon" class="h-5 w-5" />
                    </span>

                    <span class="min-w-0">
                        <span class="block break-words text-sm font-semibold text-gray-950 group-hover:underline dark:text-white">
                            {{ $report->label }}
                        </span>
                        <span class="mt-0.5 block text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">
                            {{ $report->category->label() }}
                        </span>
                        <span class="mt-1 line-clamp-2 block text-xs leading-snug text-gray-500 dark:text-gray-400">
                            {{ $report->description }}
                        </span>
                    </span>
                </a>
            @endforeach
        </div>

        @if (count($dashboard->additionalReports) > 0)
            <details class="group mt-3">
                <summary class="cursor-pointer list-none rounded-lg px-2 py-1.5 text-xs font-semibold text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400">
                    Show {{ count($dashboard->additionalReports) }} more report{{ count($dashboard->additionalReports) === 1 ? '' : 's' }}
                </summary>

                <ul class="mt-2 grid grid-cols-1 gap-1.5 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($dashboard->additionalReports as $report)
                        <li>
                            <a
                                href="{{ $report->url }}"
                                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-700 ring-1 ring-gray-950/5 transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5"
                            >
                                <x-filament::icon :icon="$report->icon" class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />
                                <span class="truncate">{{ $report->label }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </section>
@endif
