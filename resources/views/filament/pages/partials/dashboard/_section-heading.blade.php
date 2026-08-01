{{--
    Consistent heading for every major dashboard section.

    @param string $id        Anchors the section's aria-labelledby.
    @param string $eyebrow   Small gradient category label.
    @param string $title
    @param string $purpose   One line saying what the section is for.
    @param string $icon      Heroicon name.
    @param string $domain    Domain class from App\Dashboard\Support\DashboardPalette.
    @param ?string $actionUrl
    @param ?string $actionLabel
--}}
<div class="{{ $domain ?? 'dash-d-brand' }} mb-4 flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
    <div class="min-w-0">
        <p class="dash-eyebrow">
            <x-filament::icon :icon="$icon" class="h-3.5 w-3.5 shrink-0" style="color: var(--a)" />
            {{ $eyebrow }}
        </p>
        <h2 id="{{ $id }}" class="mt-1 text-lg font-semibold tracking-tight text-gray-950 dark:text-white">
            {{ $title }}
        </h2>
        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $purpose }}</p>
    </div>

    @if (! empty($actionUrl) && ! empty($actionLabel))
        <a
            href="{{ $actionUrl }}"
            class="group inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-gray-950/10 px-3 py-1.5 text-xs font-semibold text-gray-700 transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5"
        >
            {{ $actionLabel }}
            <x-filament::icon icon="heroicon-m-arrow-right" class="dash-go h-3.5 w-3.5 shrink-0" />
        </a>
    @endif
</div>
