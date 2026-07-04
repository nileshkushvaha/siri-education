@props(['items' => []])

<nav aria-label="Breadcrumb" {{ $attributes }}>
    <ol class="flex flex-wrap items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
        @forelse($items as $item)
            @php
                $label = is_array($item) ? ($item['label'] ?? '') : $item;
                $url = is_array($item) ? ($item['url'] ?? null) : null;
                $current = is_array($item) ? (bool) ($item['current'] ?? false) : $loop->last;
            @endphp
            <li class="flex items-center gap-1.5">
                @if(! $loop->first)
                    <svg class="h-4 w-4 text-slate-400 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                @endif

                @if($url && ! $current)
                    <a href="{{ $url }}" class="font-medium text-slate-600 transition hover:text-indigo-600 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:text-slate-300 dark:hover:text-indigo-300 dark:focus-visible:ring-indigo-400/20">{{ $label }}</a>
                @else
                    <span class="font-medium text-slate-900 dark:text-slate-100" @if($current) aria-current="page" @endif>{{ $label }}</span>
                @endif
            </li>
        @empty
            {{ $slot }}
        @endforelse
    </ol>
</nav>
