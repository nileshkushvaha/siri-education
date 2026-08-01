{{--
    Secondary administration area.

    User creation, settings, security, the activity log and login
    history are legitimate destinations but are not daily marketplace
    operations, so they stay below the business content.

    Rendered as small colour-accented cards on a single desktop row.
    The colour lives in a compact icon capsule rather than the card
    surface, which keeps the section subordinate to the domain cards and
    the attention grid above it — these are shortcuts, not KPIs.

    Page authoring, post authoring and role creation are absent from the
    dashboard entirely; they belong to their own sidebar modules.
--}}
@if (count($dashboard->administrationLinks) > 0)
    <section aria-labelledby="dashboard-administration-heading" class="border-t border-gray-950/5 pt-5 dark:border-white/10">
        <h2 id="dashboard-administration-heading" class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            Administration
        </h2>

        {{-- One row from `lg` up; two or three columns below so the
             targets stay finger-friendly on a phone. --}}
        <div class="mt-2.5 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($dashboard->administrationLinks as $link)
                <a
                    href="{{ $link['url'] }}"
                    title="{{ $link['description'] }}"
                    class="{{ \App\Dashboard\Support\DashboardPalette::administrationClass($link['label']) }} dash-card dash-card-row dash-card-interactive dash-wash group min-h-14 items-center gap-2.5 px-3 py-2.5"
                >
                    <span class="dash-capsule h-7 w-7 shrink-0">
                        <x-filament::icon :icon="$link['icon']" class="h-3.5 w-3.5" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-semibold text-gray-800 dark:text-gray-100">
                            {{ $link['label'] }}
                        </span>
                    </span>

                    <x-filament::icon
                        icon="heroicon-m-arrow-right"
                        class="dash-go h-3 w-3 shrink-0 text-gray-400 opacity-0 transition-opacity group-hover:opacity-100 motion-reduce:transition-none dark:text-gray-500"
                    />
                </a>
            @endforeach
        </div>
    </section>
@endif
