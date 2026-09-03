@props(['crumbs' => []])

<nav class="flex items-center gap-1.5 text-xs text-fg-muted mb-6" aria-label="Breadcrumb">
    <a href="{{ route('home') }}" class="flex min-h-11 items-center gap-1 text-fg-muted transition-colors hover:text-fg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
        </svg>
        Home
    </a>

    @foreach($crumbs as $crumb)
        <svg class="h-3 w-3 flex-shrink-0 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>

        @if(isset($crumb['url']))
            <a href="{{ $crumb['url'] }}" class="flex min-h-11 max-w-[160px] items-center truncate text-fg-muted transition-colors hover:text-fg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
                {{ $crumb['label'] }}
            </a>
        @else
            <span class="text-fg-muted font-medium truncate max-w-[220px]">
                {{ $crumb['label'] }}
            </span>
        @endif
    @endforeach
</nav>
