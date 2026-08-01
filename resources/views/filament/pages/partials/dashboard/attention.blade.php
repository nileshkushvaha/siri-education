{{--
    Needs Attention — the first substantive section.

    Cards, not a table. At most six are visible; anything beyond that
    collapses into a permission-aware overflow disclosure so the section
    never becomes a wall.

    Zero-count categories are hidden, EXCEPT the financial-integrity
    invariants (wallet/ledger and settlement allocation), where zero is
    the meaningful result. Those render as a quieter healthy confirmation
    that must never compete visually with an urgent red card.

    Every card is entirely clickable, states its severity in words as
    well as colour, and shows whether it is an "as of now" figure or a
    fixed rolling window.
--}}
@php
    $visible = $attention->visible();
    $overflow = $attention->overflow();

    // Balance the grid so the final card is never stranded:
    //   4 cards → 2×2 (a 3-column grid would leave one alone on row 2)
    //   5 cards → 3 + 2, with the last spanning the spare column
    //   otherwise → 3 per row
    $count = count($visible);
    $gridColumns = $count === 4 ? 'xl:grid-cols-2' : 'xl:grid-cols-3';
    $spanLast = $count === 5;
@endphp

<section aria-labelledby="dashboard-attention-heading">

    @include('filament.pages.partials.dashboard._section-heading', [
        'id' => 'dashboard-attention-heading',
        'eyebrow' => 'Operations',
        'title' => 'Needs attention',
        'purpose' => 'Items requiring action now — current state, not affected by the reporting period.',
        'icon' => 'heroicon-m-bolt',
        'domain' => 'dash-d-critical',
        'actionUrl' => $attention->overflowUrl,
        'actionLabel' => $attention->overflowUrl !== null ? 'All alerts' : null,
    ])

    @if (count($visible) === 0)
        <div class="dash-d-healthy dash-card dash-card-row dash-wash items-center gap-3.5 p-4">
            <span class="dash-capsule h-10 w-10 shrink-0">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-950 dark:text-white">Nothing needs attention</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    No open alerts, stuck lessons, or queues awaiting a decision in the areas you can see.
                </p>
            </div>
        </div>
    @else
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 {{ $gridColumns }}">
            @foreach ($visible as $index => $item)
                @php
                    $severity = $item->effectiveSeverity();
                    $isHealthy = $severity === \App\Dashboard\Enums\AttentionSeverity::Success;
                    $isCritical = $severity === \App\Dashboard\Enums\AttentionSeverity::Critical;
                    // High shares the critical ramp, so it must share the
                    // critical count colour too — an amber number on a red
                    // card reads as a mismatch.
                    $isUrgent = $isCritical || $severity === \App\Dashboard\Enums\AttentionSeverity::High;

                    $domain = match (true) {
                        $isHealthy => 'dash-d-healthy',
                        $isCritical,
                        $severity === \App\Dashboard\Enums\AttentionSeverity::High => 'dash-d-critical',
                        $severity === \App\Dashboard\Enums\AttentionSeverity::Warning => 'dash-d-finance',
                        default => 'dash-d-ops',
                    };

                    $isLast = $spanLast && $index === count($visible) - 1;
                @endphp

                <a
                    href="{{ $item->url }}"
                    @class([
                        $domain,
                        'dash-card dash-attn group p-4',
                        'dash-attn-critical' => $isCritical,
                        'dash-attn-healthy' => $isHealthy,
                        // Only an unresolved critical item breathes, and
                        // reduced-motion disables it entirely.
                        'dash-pulse' => $isCritical && $item->count > 0,
                        'xl:col-span-2' => $isLast,
                    ])
                    aria-label="{{ $item->label }}: {{ $item->count }}. {{ $severity->label() }}. Opens {{ $item->destinationLabel }}."
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <span @class(['dash-capsule h-9 w-9 shrink-0', 'opacity-90' => $isHealthy])>
                                <x-filament::icon :icon="$item->icon" class="h-4 w-4" />
                            </span>

                            <div class="min-w-0">
                                <p class="break-words text-sm font-semibold leading-snug text-gray-950 dark:text-white">
                                    {{ $item->label }}
                                </p>
                                {{-- Severity is stated in words, never by colour alone. --}}
                                <span @class([
                                    'dash-pill mt-1',
                                    'dash-pill-critical' => $isUrgent,
                                    'dash-pill-warning' => ! $isUrgent && ! $isHealthy,
                                    'dash-pill-healthy' => $isHealthy,
                                ])>
                                    <x-filament::icon :icon="$severity->icon()" class="h-3 w-3 shrink-0" />
                                    {{ $severity->label() }}
                                </span>
                            </div>
                        </div>

                        <span @class([
                            'shrink-0 text-3xl font-bold leading-none tabular-nums',
                            'text-danger-600 dark:text-danger-400' => $isUrgent,
                            'text-warning-600 dark:text-warning-400' => ! $isUrgent && ! $isHealthy,
                            'text-success-600 dark:text-success-400' => $isHealthy,
                        ])>
                            {{ number_format($item->count) }}
                        </span>
                    </div>

                    <p class="mt-3 flex-1 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                        {{ $item->explanation }}
                    </p>

                    <p class="mt-3 flex items-center justify-between gap-2 border-t border-gray-950/5 pt-2.5 text-[11px] text-gray-500 dark:border-white/5 dark:text-gray-400">
                        <span>{{ $item->asOfLabel }}</span>
                        <span class="inline-flex items-center gap-1 font-medium text-gray-700 group-hover:underline dark:text-gray-200">
                            {{ $item->destinationLabel }}
                            <x-filament::icon icon="heroicon-m-arrow-right" class="dash-go h-3 w-3 shrink-0" />
                        </span>
                    </p>
                </a>
            @endforeach
        </div>

        @if (count($overflow) > 0)
            <details class="group mt-3">
                <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-lg border border-gray-950/10 px-3 py-1.5 text-xs font-semibold text-gray-700 transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5">
                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-180 motion-reduce:transition-none" />
                    {{ count($overflow) }} more attention item{{ count($overflow) === 1 ? '' : 's' }}
                </summary>

                <ul class="mt-2 grid grid-cols-1 gap-1.5 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($overflow as $item)
                        @php
                            $severity = $item->effectiveSeverity();
                            $isHealthy = $severity === \App\Dashboard\Enums\AttentionSeverity::Success;
                        @endphp
                        <li>
                            <a
                                href="{{ $item->url }}"
                                class="{{ $isHealthy ? 'dash-d-healthy' : 'dash-d-critical' }} dash-card dash-card-row dash-card-interactive items-center justify-between gap-3 px-3 py-2 text-sm"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <x-filament::icon :icon="$item->icon" class="h-4 w-4 shrink-0" style="color: var(--a)" />
                                    <span class="truncate text-gray-950 dark:text-white">{{ $item->label }}</span>
                                    <span class="sr-only">{{ $severity->label() }}</span>
                                </span>
                                <span class="shrink-0 font-semibold tabular-nums text-gray-950 dark:text-white">
                                    {{ number_format($item->count) }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    @endif
</section>
