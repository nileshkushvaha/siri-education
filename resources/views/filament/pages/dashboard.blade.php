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

    {{-- `fi-dashboard` scopes the entire design system so no other
         admin page is affected. Section rhythm is wider than card
         rhythm so related cards stay visually grouped. --}}
    <div class="fi-dashboard space-y-8" wire:key="dashboard-{{ $chartKey }}">

        @include('filament.pages.partials.dashboard.context-bar', [
            'context' => $context,
            'dashboard' => $dashboard,
            'attention' => $attention,
            'greeting' => $this->greeting(),
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
            @include('filament.pages.partials.dashboard._empty-state', [
                'message' => 'Marketplace figures require reporting permissions. Ask an administrator if you need them.',
                'icon' => 'heroicon-o-lock-closed',
                'tone' => 'unavailable',
                'domain' => 'dash-d-brand',
                'url' => null,
                'linkLabel' => null,
            ])

            <p class="text-center text-sm font-medium text-gray-950 dark:text-white">
                No reporting sections are available to you
            </p>
        @endif

        @include('filament.pages.partials.dashboard.launchpad', ['dashboard' => $dashboard])

        @include('filament.pages.partials.dashboard.administration', ['dashboard' => $dashboard])

        @include('filament.pages.partials.dashboard.system-health', ['dashboard' => $dashboard])

    </div>

</x-filament-panels::page>
