{{-- CMS Hero Block — light public-site presentation; content and media remain administrator managed. --}}
<section class="relative overflow-hidden border-b border-indigo-100 bg-gradient-to-br from-[#f8fbff] via-white to-[#fff4ef] py-14 sm:py-16 lg:py-20">
    <div class="pointer-events-none absolute -left-24 top-12 h-80 w-80 rounded-full bg-cyan-200/35 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -right-24 bottom-0 h-96 w-96 rounded-full bg-violet-200/40 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute inset-0 opacity-30" style="background-image:radial-gradient(#818cf8 .7px,transparent .7px);background-size:24px 24px" aria-hidden="true"></div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid items-center gap-12 md:grid-cols-2">
            <div>
                <span class="inline-flex rounded-full border border-indigo-200 bg-white/85 px-4 py-2 text-xs font-black uppercase tracking-[0.15em] text-indigo-700 shadow-sm">Personalised education</span>
                @if($title ?? false)
                    <h1 class="mt-6 text-4xl font-black leading-tight tracking-tight text-slate-950 md:text-5xl lg:text-6xl">{{ $title }}</h1>
                @endif
                @if($subtitle ?? false)
                    <p class="mt-6 text-lg leading-8 text-slate-600">{{ $subtitle }}</p>
                @endif
                @if(($button_text ?? false) && ($button_link ?? false))
                    <div class="mt-8">
                        <a href="{{ $button_link }}" class="inline-flex min-h-12 items-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-600 px-6 text-sm font-black text-white shadow-xl shadow-indigo-200 transition hover:-translate-y-0.5 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200">
                            {{ $button_text }}
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                        </a>
                    </div>
                @endif
            </div>
            @if($image ?? false)
                <div class="relative">
                    <div class="absolute -inset-5 rounded-[2rem] bg-gradient-to-br from-cyan-300/30 via-indigo-300/30 to-rose-300/35 blur-2xl" aria-hidden="true"></div>
                    <img src="{{ $image }}" alt="{{ $title ?? 'Featured learning experience' }}" class="relative aspect-[4/3] w-full rounded-[2rem] border border-white object-cover shadow-2xl shadow-indigo-200/60">
                </div>
            @endif
        </div>
    </div>
</section>
