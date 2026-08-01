{{--
    Needs Attention — the first substantive section.

    Cards, not a table. At most six are visible; anything beyond that
    collapses into a permission-aware overflow disclosure so the section
    never becomes a wall.

    Zero-count categories are hidden, EXCEPT the financial-integrity
    invariants (wallet/ledger and settlement allocation), where zero is
    the meaningful result and renders as a small healthy confirmation.

    Every card is entirely clickable, states its severity in words as
    well as colour, and shows whether it is an "as of now" figure or a
    fixed rolling window.
--}}
@php
    $visible = $attention->visible();
    $overflow = $attention->overflow();
@endphp

<section aria-labelledby="dashboard-attention-heading">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 id="dashboard-attention-heading" class="text-base font-semibold text-gray-950 dark:text-white">
                Needs attention
            </h2>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                Current state — not affected by the reporting period above.
            </p>
        </div>

        @if ($attention->overflowUrl !== null && count($overflow) > 0)
            <a
                href="{{ $attention->overflowUrl }}"
                class="rounded-lg px-2 py-1 text-xs font-semibold text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400"
            >
                {{ count($overflow) }} more
            </a>
        @endif
    </div>

    @if (count($visible) === 0)
        <div class="fi-section flex items-center gap-3 rounded-xl bg-success-50 p-4 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:ring-success-400/30">
            <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6 shrink-0 text-success-700 dark:text-success-400" />
            <div>
                <p class="text-sm font-medium text-success-900 dark:text-success-200">Nothing needs attention</p>
                <p class="text-xs text-success-800/80 dark:text-success-300/80">
                    No open alerts, stuck lessons, or queues awaiting a decision in the areas you can see.
                </p>
            </div>
        </div>
    @else
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($visible as $item)
                @php $severity = $item->effectiveSeverity(); @endphp

                <a
                    href="{{ $item->url }}"
                    class="group flex h-full flex-col justify-between gap-3 rounded-xl p-4 ring-1 transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 motion-reduce:transition-none dark:focus-visible:ring-offset-gray-900 {{ $severity->surfaceClasses() }}"
                    aria-label="{{ $item->label }}: {{ $item->count }}. {{ $severity->label() }}. Opens {{ $item->destinationLabel }}."
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-2.5">
                            <x-filament::icon :icon="$item->icon" class="mt-0.5 h-5 w-5 shrink-0 {{ $severity->accentClasses() }}" />
                            <div class="min-w-0">
                                <p class="break-words text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ $item->label }}
                                </p>
                                {{-- Severity is stated in words, never by colour alone. --}}
                                <p class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-medium uppercase tracking-wide {{ $severity->accentClasses() }}">
                                    <x-filament::icon :icon="$severity->icon()" class="h-3 w-3 shrink-0" />
                                    {{ $severity->label() }}
                                </p>
                            </div>
                        </div>

                        <span class="shrink-0 text-2xl font-bold tabular-nums {{ $severity->accentClasses() }}">
                            {{ number_format($item->count) }}
                        </span>
                    </div>

                    <div>
                        <p class="text-xs leading-snug text-gray-600 dark:text-gray-300">
                            {{ $item->explanation }}
                        </p>
                        <p class="mt-2 flex items-center justify-between gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                            <span>{{ $item->asOfLabel }}</span>
                            <span class="inline-flex items-center gap-1 font-medium group-hover:underline">
                                {{ $item->destinationLabel }}
                                <x-filament::icon icon="heroicon-m-arrow-right" class="h-3 w-3 shrink-0" />
                            </span>
                        </p>
                    </div>
                </a>
            @endforeach
        </div>

        @if (count($overflow) > 0)
            <details class="group mt-3">
                <summary class="cursor-pointer list-none rounded-lg px-2 py-1.5 text-xs font-semibold text-primary-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400">
                    Show {{ count($overflow) }} more attention item{{ count($overflow) === 1 ? '' : 's' }}
                </summary>

                <ul class="mt-2 space-y-1.5">
                    @foreach ($overflow as $item)
                        @php $severity = $item->effectiveSeverity(); @endphp
                        <li>
                            <a
                                href="{{ $item->url }}"
                                class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm ring-1 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 motion-reduce:transition-none {{ $severity->surfaceClasses() }}"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <x-filament::icon :icon="$item->icon" class="h-4 w-4 shrink-0 {{ $severity->accentClasses() }}" />
                                    <span class="truncate text-gray-950 dark:text-white">{{ $item->label }}</span>
                                    <span class="sr-only">{{ $severity->label() }}</span>
                                </span>
                                <span class="shrink-0 font-semibold tabular-nums {{ $severity->accentClasses() }}">
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
