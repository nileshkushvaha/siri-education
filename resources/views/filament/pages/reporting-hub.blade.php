<x-filament-panels::page>

    <div class="space-y-6">
        @foreach($this->categories() as $row)
            @php [$category, $available, $planned] = [$row['category'], $row['available'], $row['planned']]; @endphp

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-header flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <x-filament::icon :icon="$category->icon()" class="h-5 w-5 text-gray-400" />
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $category->label() }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $category->description() }}</p>
                    </div>
                </div>

                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach($available as $definition)
                        <a
                            href="{{ $this->reportUrl($definition) }}"
                            aria-label="Open {{ $definition->label }}"
                            class="flex items-center justify-between gap-3 px-6 py-3 transition hover:bg-gray-50 dark:hover:bg-white/5"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $definition->label }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $definition->description }}</p>
                            </div>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-success-50 px-2.5 py-1 text-xs font-medium text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-500/20">
                                <span class="h-1.5 w-1.5 rounded-full bg-success-500"></span>
                                Available
                            </span>
                        </a>
                    @endforeach

                    @foreach($planned as $definition)
                        <div
                            aria-label="{{ $definition->label }} — planned, not yet available"
                            class="flex items-center justify-between gap-3 px-6 py-3 opacity-60"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $definition->label }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $definition->description }}</p>
                            </div>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-500 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20">
                                <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                                Planned
                            </span>
                        </div>
                    @endforeach

                    @if($available->isEmpty() && $planned->isEmpty())
                        <p class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">No reports registered in this category yet.</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

</x-filament-panels::page>
