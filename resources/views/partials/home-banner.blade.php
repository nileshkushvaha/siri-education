@php
$countries = ['🇮🇳 India', '🇺🇸 United States', '🇬🇧 United Kingdom', '🇨🇦 Canada', '🇦🇺 Australia', '🌐 All Regions'];
@endphp

{{-- ============================================================
     HOME HERO BANNER — included on the homepage only
     ============================================================ --}}
<div class="bg-surface-dark" x-data="{ activeCountry: '🇮🇳 India' }">

    @include('components.blocks.hero-carousel', ['use_default_slides' => true])

    {{-- Country Selector Banner --}}
    <div class="max-w-4xl mx-auto px-4 w-full pb-16 relative z-10">
        <div class="glass-md rounded-2xl p-5 shadow-2xl shadow-indigo-500/10">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-8 h-8 rounded-xl bg-indigo-500/20 border border-indigo-500/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <p class="text-white text-sm font-semibold">Select Your Country</p>
                    <p class="text-gray-400 text-xs">Find tutors and courses tailored to your curriculum</p>
                </div>
                <div class="ml-auto flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 flex-shrink-0">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                    <span class="text-emerald-400 text-xs font-medium" x-text="activeCountry || '🇮🇳 India'"></span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                @foreach($countries as $country)
                <button
                    @click="activeCountry = '{{ $country }}'"
                    :class="activeCountry === '{{ $country }}'
                            ? 'bg-indigo-500/25 border-indigo-500/60 text-indigo-300 shadow-lg shadow-indigo-500/10'
                            : 'text-gray-400 hover:text-gray-200 hover:border-white/25'"
                    class="glass px-4 py-2 rounded-xl text-sm font-medium whitespace-nowrap transition-all duration-200 border border-white/10 flex items-center gap-1.5">
                    {{ $country }}
                    <span x-show="activeCountry === '{{ $country }}'" class="w-1.5 h-1.5 rounded-full bg-indigo-400 inline-block flex-shrink-0"></span>
                </button>
                @endforeach
            </div>

            <div class="mt-4 pt-4 border-t border-white/10 flex items-center justify-between">
                <p class="text-gray-500 text-xs">
                    Showing tutors available in <span class="text-white font-medium" x-text="activeCountry || '🇮🇳 India'"></span>
                </p>
                @if(Route::has('auth.register'))
                <a href="{{ route('auth.register') }}" class="btn-amber px-5 py-2 rounded-xl text-white font-bold text-sm">Find Tutors →</a>
                @else
                <a href="{{ route('auth.register') }}" class="btn-amber px-5 py-2 rounded-xl text-white font-bold text-sm">Find Tutors →</a>
                @endif
            </div>
        </div>
    </div>

</div>{{-- /x-data activeCountry --}}
