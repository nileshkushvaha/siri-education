@props([
    'paginator' => null,
    'window' => 2,
])

@if($paginator && $paginator->hasPages())
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $start = max(1, $current - $window);
        $end = min($last, $current + $window);
        $button = 'inline-flex h-9 min-w-9 items-center justify-center rounded-xl px-3 text-sm font-semibold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:focus-visible:ring-indigo-400/20';
        $enabled = 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10';
        $active = 'bg-indigo-600 text-white dark:bg-indigo-500';
        $disabled = 'cursor-not-allowed border border-slate-200 bg-slate-50 text-slate-400 dark:border-white/10 dark:bg-white/[0.03] dark:text-slate-600';
    @endphp

    <nav role="navigation" aria-label="Pagination" {{ $attributes->merge(['class' => 'flex flex-wrap items-center justify-between gap-3']) }}>
        <p class="text-sm text-slate-600 dark:text-slate-400">
            Showing <span class="font-medium text-slate-900 dark:text-slate-100">{{ $paginator->firstItem() }}</span>
            to <span class="font-medium text-slate-900 dark:text-slate-100">{{ $paginator->lastItem() }}</span>
            of <span class="font-medium text-slate-900 dark:text-slate-100">{{ $paginator->total() }}</span>
        </p>
        <div class="flex items-center gap-1">
            @if($paginator->onFirstPage())
                <span class="{{ $button }} {{ $disabled }}" aria-disabled="true">Previous</span>
            @else
                <a class="{{ $button }} {{ $enabled }}" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
            @endif

            @if($start > 1)
                <a class="{{ $button }} {{ $enabled }}" href="{{ $paginator->url(1) }}">1</a>
                @if($start > 2)<span class="px-2 text-slate-400">...</span>@endif
            @endif

            @for($page = $start; $page <= $end; $page++)
                @if($page === $current)
                    <span class="{{ $button }} {{ $active }}" aria-current="page">{{ $page }}</span>
                @else
                    <a class="{{ $button }} {{ $enabled }}" href="{{ $paginator->url($page) }}">{{ $page }}</a>
                @endif
            @endfor

            @if($end < $last)
                @if($end < $last - 1)<span class="px-2 text-slate-400">...</span>@endif
                <a class="{{ $button }} {{ $enabled }}" href="{{ $paginator->url($last) }}">{{ $last }}</a>
            @endif

            @if($paginator->hasMorePages())
                <a class="{{ $button }} {{ $enabled }}" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
            @else
                <span class="{{ $button }} {{ $disabled }}" aria-disabled="true">Next</span>
            @endif
        </div>
    </nav>
@endif
