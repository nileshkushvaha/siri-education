{{--
    Global context: reporting period, timezone and the optional country
    dimension, plus honest freshness.

    Two freshness statements are shown, never merged: the period-scoped
    composition (cached, ~5 min) and the Needs Attention feed (cached,
    ~1 min). Presenting an alert count with the period section's older
    timestamp would overstate how stale it is — and vice versa.

    Country is the only page-wide optional filter, because it is the one
    dimension supported broadly enough across report definitions. Charts
    whose owning calculation has no country dimension say so on their
    own subtitle rather than pretending the filter applied.
--}}
<div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">

    <div class="grid grid-cols-1 gap-4 p-4 sm:p-5 lg:grid-cols-12">

        {{-- Period --}}
        <div class="lg:col-span-3">
            <label for="dashboard-period" class="block text-xs font-semibold text-gray-500 dark:text-gray-400">
                Reporting period
            </label>
            <select
                id="dashboard-period"
                wire:model.live="periodPreset"
                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
            >
                @foreach ($this->periodPresets() as $preset)
                    <option value="{{ $preset->value }}">{{ $preset->label() }}</option>
                @endforeach
            </select>
        </div>

        @if ($this->periodPreset === 'custom')
            <div class="lg:col-span-2">
                <label for="dashboard-start" class="block text-xs font-semibold text-gray-500 dark:text-gray-400">Start date</label>
                <input id="dashboard-start" type="date" wire:model.live="customStart"
                       class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white" />
            </div>
            <div class="lg:col-span-2">
                <label for="dashboard-end" class="block text-xs font-semibold text-gray-500 dark:text-gray-400">End date</label>
                <input id="dashboard-end" type="date" wire:model.live="customEnd"
                       class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white" />
            </div>
        @endif

        {{-- Country --}}
        <div class="lg:col-span-3">
            <label for="dashboard-country" class="block text-xs font-semibold text-gray-500 dark:text-gray-400">
                Country
            </label>
            <select
                id="dashboard-country"
                wire:model.live="countryId"
                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
            >
                <option value="">All countries</option>
                @foreach ($this->countryOptions() as $country)
                    <option value="{{ $country->id }}">{{ $country->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-end lg:col-span-2">
            <button
                type="button"
                wire:click="resetFilters"
                class="rounded-lg px-2 py-1.5 text-xs font-semibold text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400"
            >
                Reset filters
            </button>
        </div>
    </div>

    {{-- An invalid custom range is never silently swapped for different data. --}}
    @if ($this->customRangeWasRejected())
        <div class="border-t border-warning-200 bg-warning-50 px-4 py-2.5 dark:border-warning-500/20 dark:bg-warning-500/10 sm:px-5">
            <p class="flex items-start gap-2 text-xs text-warning-800 dark:text-warning-300">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-px h-4 w-4 shrink-0" />
                <span>
                    That custom range was not accepted — the end date must not precede the start date, and the range
                    may not exceed {{ \App\Reporting\ValueObjects\ReportingPeriod::MAX_CUSTOM_RANGE_DAYS }} days.
                    Showing <strong>{{ $context->periodLabel() }}</strong> instead.
                </span>
            </p>
        </div>
    @endif

    {{-- Freshness. Two separate statements, deliberately not merged. --}}
    <div class="border-t border-gray-950/5 px-4 py-3 dark:border-white/10 sm:px-5">
        <div class="flex flex-col gap-x-6 gap-y-1.5 text-xs text-gray-500 sm:flex-row sm:flex-wrap sm:items-center dark:text-gray-400">
            <span class="inline-flex items-center gap-1.5">
                <x-filament::icon icon="heroicon-o-clock" class="h-3.5 w-3.5 shrink-0" />
                <span>
                    Period figures: <span class="font-medium text-gray-950 dark:text-white">{{ $dashboard->freshness->label() }}</span>,
                    generated {{ $dashboard->freshness->generatedAtLabel() }}
                </span>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-filament::icon icon="heroicon-o-bell-alert" class="h-3.5 w-3.5 shrink-0" />
                <span>
                    Attention counts: as of {{ $attention->generatedAtLabel() }}
                </span>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-filament::icon icon="heroicon-o-globe-alt" class="h-3.5 w-3.5 shrink-0" />
                <span>
                    Timezone <span class="font-medium text-gray-950 dark:text-white">{{ $context->timezone() }}</span>
                    · {{ $context->period->label }}
                </span>
            </span>
        </div>
    </div>
</div>
