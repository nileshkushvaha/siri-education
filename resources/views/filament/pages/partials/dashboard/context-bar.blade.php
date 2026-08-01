{{--
    Hero + global filter toolbar.

    The greeting, the marketplace-health line, the actions and the
    period/country controls form one connected surface rather than a
    heading followed by a plain form panel.

    Two freshness statements are still shown and still never merged: the
    period-scoped composition (cached, ~5 min) and the Needs Attention
    feed (cached, ~1 min). Presenting an alert count with the period
    section's older timestamp would overstate how stale it is — and vice
    versa. They sit in a metadata strip so they stay available without
    dominating the page.

    Country remains the only page-wide optional filter, because it is the
    one dimension supported broadly enough across report definitions.
    Charts whose owning calculation has no country dimension say so in
    their own subtitle rather than pretending the filter applied.
--}}
<section class="dash-hero" aria-labelledby="dashboard-hero-heading">

    <div class="flex flex-col gap-5 p-5 sm:p-6">

        {{-- Greeting + actions --}}
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="dash-eyebrow">
                    <span class="inline-block h-1.5 w-1.5 rounded-full" style="background: var(--dash-brand-a)"></span>
                    Marketplace command centre
                </p>
                <h2 id="dashboard-hero-heading" class="mt-1.5 text-xl font-bold tracking-tight text-gray-950 sm:text-2xl dark:text-white">
                    {{ $greeting }}
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Marketplace health, open work, and where to look next.
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <button
                    type="button"
                    wire:click="refreshDashboard"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-950/10 bg-white/60 px-3 py-1.5 text-xs font-semibold text-gray-700 transition-colors hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10"
                >
                    <x-filament::icon icon="heroicon-m-arrow-path" class="h-3.5 w-3.5 shrink-0" />
                    Refresh
                </button>

                <a
                    href="/"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-950/10 bg-white/60 px-3 py-1.5 text-xs font-semibold text-gray-700 transition-colors hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10"
                >
                    <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-3.5 w-3.5 shrink-0" />
                    View Site
                </a>
            </div>
        </div>

        {{-- Filter toolbar --}}
        <div class="flex flex-wrap items-end gap-x-3 gap-y-3 rounded-xl border border-gray-950/5 bg-gray-50/70 p-3 dark:border-white/5 dark:bg-white/[0.03]">
            <div class="min-w-0 flex-1 basis-48">
                <label for="dashboard-period" class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Period
                </label>
                <select
                    id="dashboard-period"
                    wire:model.live="periodPreset"
                    class="w-full rounded-lg border-gray-300 py-1.5 text-sm font-medium dark:border-white/10 dark:bg-gray-900 dark:text-white"
                >
                    @foreach ($this->periodPresets() as $preset)
                        <option value="{{ $preset->value }}">{{ $preset->label() }}</option>
                    @endforeach
                </select>
            </div>

            @if ($this->periodPreset === 'custom')
                <div class="min-w-0 flex-1 basis-36">
                    <label for="dashboard-start" class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">From</label>
                    <input id="dashboard-start" type="date" wire:model.live="customStart"
                           class="w-full rounded-lg border-gray-300 py-1.5 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white" />
                </div>
                <div class="min-w-0 flex-1 basis-36">
                    <label for="dashboard-end" class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">To</label>
                    <input id="dashboard-end" type="date" wire:model.live="customEnd"
                           class="w-full rounded-lg border-gray-300 py-1.5 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white" />
                </div>
            @endif

            <div class="min-w-0 flex-1 basis-48">
                <label for="dashboard-country" class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Country
                </label>
                <select
                    id="dashboard-country"
                    wire:model.live="countryId"
                    class="w-full rounded-lg border-gray-300 py-1.5 text-sm dark:border-white/10 dark:bg-gray-900 dark:text-white"
                >
                    <option value="">All countries</option>
                    @foreach ($this->countryOptions() as $country)
                        <option value="{{ $country->id }}">{{ $country->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Active period stated plainly; Reset stays secondary. --}}
            <div class="flex shrink-0 items-center gap-3 pb-0.5">
                <span class="dash-pill dash-pill-idle">
                    <x-filament::icon icon="heroicon-m-calendar-days" class="h-3 w-3 shrink-0" />
                    {{ $context->periodLabel() }}
                </span>

                <button
                    type="button"
                    wire:click="resetFilters"
                    class="rounded text-[11px] font-medium text-gray-500 underline-offset-2 transition-colors hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:text-gray-400 dark:hover:text-white"
                >
                    Reset filters
                </button>
            </div>
        </div>

        {{-- An invalid custom range is never silently swapped for different data. --}}
        @if ($this->customRangeWasRejected())
            <p class="flex items-start gap-2 rounded-lg border border-warning-500/25 bg-warning-50 p-2.5 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="mt-px h-4 w-4 shrink-0" />
                <span>
                    That custom range was not accepted — the end date must not precede the start date, and the range
                    may not exceed {{ \App\Reporting\ValueObjects\ReportingPeriod::MAX_CUSTOM_RANGE_DAYS }} days.
                    Showing <strong>{{ $context->periodLabel() }}</strong> instead.
                </span>
            </p>
        @endif
    </div>

    <div class="dash-hero-rule" aria-hidden="true"></div>

    {{-- Freshness metadata: present, but subordinate. --}}
    <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5 px-5 py-2.5 text-[11px] text-gray-500 sm:px-6 dark:text-gray-400">
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500" aria-hidden="true"></span>
            <span>
                <span class="sr-only">Dashboard data status: </span>
                Period figures: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $dashboard->freshness->label() }}</span>,
                generated {{ $dashboard->freshness->generatedAtLabel() }}
            </span>
        </span>

        <span class="inline-flex items-center gap-1.5">
            <x-filament::icon icon="heroicon-m-bell-alert" class="h-3 w-3 shrink-0" />
            Attention counts: as of {{ $attention->generatedAtLabel() }}
        </span>

        <span class="inline-flex items-center gap-1.5">
            <x-filament::icon icon="heroicon-m-globe-alt" class="h-3 w-3 shrink-0" />
            Timezone <span class="font-medium text-gray-700 dark:text-gray-300">{{ $context->timezone() }}</span>
            · {{ $context->period->label }}
        </span>
    </div>
</section>
