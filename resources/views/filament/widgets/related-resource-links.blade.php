@if ($links !== [])
    <x-filament-widgets::widget>
        <div class="mx-auto w-fit max-w-full overflow-x-auto rounded-2xl bg-white p-2 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/10">
            <nav class="flex min-w-max items-center gap-1" aria-label="Related pages">
                @foreach ($links as $link)
                    @php
                        $linkPath = '/' . trim((string) parse_url($link['url'], PHP_URL_PATH), '/');
                        $isActive = $activePath === $linkPath;
                    @endphp
                    <a
                        href="{{ $link['url'] }}"
                        @class([
                            'rounded-xl px-5 py-3 text-sm font-medium whitespace-nowrap transition-colors focus-visible:outline-none',
                            'bg-primary-50 text-primary-700 ring-1 ring-primary-600/20 dark:bg-primary-500/15 dark:text-primary-400 dark:ring-primary-400/30' => $isActive,
                            'text-gray-500 hover:bg-gray-100 hover:text-gray-950 focus-visible:bg-gray-100 focus-visible:text-gray-950 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-white dark:focus-visible:bg-white/10 dark:focus-visible:text-white' => ! $isActive,
                        ])
                        @if ($isActive) aria-current="page" @endif
                    >
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </nav>
        </div>
    </x-filament-widgets::widget>
@endif
