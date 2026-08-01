{{--
    Admin dashboard — marketplace command centre.

    Deliberately contains NO data tables. Sections render top-to-bottom
    in decreasing urgency: what needs action now, how the period
    performed, where to go next, then a subordinate system strip.

    Every section is permission-gated in the composition layer, not
    here: if a viewer lacks a permission, the section is absent from the
    DTO entirely (its queries never ran), and the grid simply closes the
    gap. This view never receives restricted data to hide.
--}}
<x-filament-panels::page>

    @php
        $context = $this->context();
        $attention = $this->attention();
        $dashboard = $this->dashboard();
        $chartProps = [
            'periodPreset' => $this->periodPreset,
            'customStart' => $this->customStart,
            'customEnd' => $this->customEnd,
            'countryId' => $this->countryId,
        ];
        // Re-mount charts whenever the global context changes so they
        // never render a previous period's series.
        $chartKey = md5(json_encode($chartProps));
    @endphp

    <div class="space-y-6" wire:key="dashboard-{{ $chartKey }}">

        @include('filament.pages.partials.dashboard.context-bar', [
            'context' => $context,
            'dashboard' => $dashboard,
            'attention' => $attention,
        ])

        @include('filament.pages.partials.dashboard.attention', ['attention' => $attention])

        @if ($dashboard->hasBusinessContent())

            @include('filament.pages.partials.dashboard.kpis', ['dashboard' => $dashboard])

            @include('filament.pages.partials.dashboard.charts', [
                'dashboard' => $dashboard,
                'chartProps' => $chartProps,
                'chartKey' => $chartKey,
            ])

            @include('filament.pages.partials.dashboard.summaries', [
                'dashboard' => $dashboard,
                'chartProps' => $chartProps,
                'chartKey' => $chartKey,
            ])

        @else
            <div class="fi-section rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <x-filament::icon icon="heroicon-o-lock-closed" class="mx-auto h-8 w-8 text-gray-400 dark:text-gray-500" />
                <p class="mt-3 text-sm font-medium text-gray-950 dark:text-white">No reporting sections are available to you</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Marketplace figures require reporting permissions. Ask an administrator if you need them.
                </p>
            </div>
        @endif

        @include('filament.pages.partials.dashboard.launchpad', ['dashboard' => $dashboard])

        @include('filament.pages.partials.dashboard.administration', ['dashboard' => $dashboard])

        @include('filament.pages.partials.dashboard.system-health', ['dashboard' => $dashboard])

    </div>

</x-filament-panels::page>
