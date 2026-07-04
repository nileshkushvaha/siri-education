{{--
    Breadcrumb Component
    ─────────────────────
    Usage:
    @section('breadcrumbs')
        <x-frontend.breadcrumb :crumbs="[
            ['label' => 'Dashboard', 'url' => route('dashboard')],
            ['label' => 'My Profile'],
        ]" />
    @endsection
--}}

@if(isset($crumbs) && count($crumbs) > 0)
<div class="border-b border-white/[0.04]" style="background:rgba(5,8,15,.50);">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav class="flex items-center gap-1.5 py-2.5 text-xs" aria-label="Breadcrumb">
            <a href="{{ route('home') }}"
               class="text-slate-400 hover:text-slate-400 transition-colors flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Home
            </a>

            @foreach($crumbs as $crumb)
                <svg class="w-3 h-3 text-slate-700 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>

                @if(isset($crumb['url']))
                    <a href="{{ $crumb['url'] }}"
                       class="text-slate-400 hover:text-slate-300 transition-colors truncate max-w-[160px]">
                        {{ $crumb['label'] }}
                    </a>
                @else
                    <span class="text-slate-300 font-medium truncate max-w-[220px]">
                        {{ $crumb['label'] }}
                    </span>
                @endif
            @endforeach
        </nav>
    </div>
</div>
@endif
