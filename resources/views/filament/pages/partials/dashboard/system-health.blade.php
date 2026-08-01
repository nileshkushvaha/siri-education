{{--
    Super-administrator system health.

    Renders only when the viewer holds at least one system permission —
    a manager, who is not seeded with queue/scheduler/cache permissions,
    never sees this and never triggers its queries.

    Deliberately last and visually subordinate: these are not education
    administration tasks. It must still be present, because thirteen of
    the scheduled commands are financial or lesson-lifecycle critical,
    so an unnoticed stalled queue silently breaks money movement.

    Provider activation appears here as an operational fact. No
    credential, key fragment or webhook secret is read or shown.
--}}
@if ($dashboard->systemHealth !== null && $dashboard->systemHealth->hasAnything())
    @php $health = $dashboard->systemHealth; @endphp

    <section aria-labelledby="dashboard-system-heading" class="border-t border-gray-950/5 pt-5 dark:border-white/10">
        <h2 id="dashboard-system-heading" class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            System health
        </h2>

        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">

            @if ($health->failedJobCount !== null)
                <div class="min-w-0 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Failed jobs</p>
                    <p @class([
                        'mt-0.5 text-lg font-semibold tabular-nums',
                        'text-danger-600 dark:text-danger-400' => $health->failedJobCount > 0,
                        'text-gray-950 dark:text-white' => $health->failedJobCount === 0,
                    ])>
                        {{ number_format($health->failedJobCount) }}
                    </p>
                    @if ($health->criticalJobAlertCount !== null)
                        <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                            {{ number_format($health->criticalJobAlertCount) }} open critical-job alert{{ $health->criticalJobAlertCount === 1 ? '' : 's' }}
                        </p>
                    @endif
                </div>
            @endif

            @if ($health->schedulerLastRunLabel !== null)
                <div class="min-w-0 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Scheduler</p>
                    <p class="mt-0.5 flex items-center gap-1.5 text-sm font-semibold text-gray-950 dark:text-white">
                        <x-filament::icon
                            :icon="$health->schedulerHealthy ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
                            @class([
                                'h-4 w-4 shrink-0',
                                'text-success-600 dark:text-success-400' => $health->schedulerHealthy,
                                'text-warning-600 dark:text-warning-400' => ! $health->schedulerHealthy,
                            ])
                        />
                        {{ $health->schedulerHealthy ? 'Running' : 'Check required' }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                        Last run {{ $health->schedulerLastRunLabel }}
                    </p>
                </div>
            @endif

            @foreach ($health->providers as $provider)
                <div class="min-w-0 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <p class="break-words text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ $provider->label }}
                    </p>
                    <p class="mt-0.5 flex items-center gap-1.5 text-sm font-semibold text-gray-950 dark:text-white">
                        <x-filament::icon
                            :icon="$provider->isActivated() ? 'heroicon-o-check-circle' : 'heroicon-o-pause-circle'"
                            @class([
                                'h-4 w-4 shrink-0',
                                'text-success-600 dark:text-success-400' => $provider->isActivated(),
                                'text-warning-600 dark:text-warning-400' => ! $provider->isActivated(),
                            ])
                        />
                        {{ $provider->statusLabel }}
                    </p>
                    <p class="mt-0.5 break-words text-[11px] leading-snug text-gray-500 dark:text-gray-400">
                        {{ $provider->detail }}
                    </p>
                    @if ($provider->settingsUrl !== null)
                        <a
                            href="{{ $provider->settingsUrl }}"
                            class="mt-1.5 inline-flex items-center gap-1 rounded text-[11px] font-medium text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400"
                        >
                            Configure
                            <x-filament::icon icon="heroicon-m-arrow-right" class="h-3 w-3 shrink-0" />
                        </a>
                    @endif
                </div>
            @endforeach
        </div>

        @if (count($health->links) > 0)
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($health->links as $link)
                    <a
                        href="{{ $link['url'] }}"
                        title="{{ $link['description'] }}"
                        class="inline-flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs font-medium text-gray-600 ring-1 ring-gray-950/5 transition-colors hover:bg-gray-100 hover:text-gray-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:bg-white/5 dark:text-gray-400 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white"
                    >
                        <x-filament::icon :icon="$link['icon']" class="h-4 w-4 shrink-0" />
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endif
