@php
    $content = match (true) {
        request()->routeIs('instructors.*') => [
            'eyebrow' => 'Your goals · Your pace · Your match',
            'title' => 'The right instructor can change how learning feels.',
            'description' => 'Compare expertise and availability, then choose a learning relationship shaped around your goals.',
        ],
        request()->routeIs('faqs.*') => [
            'eyebrow' => 'Still have questions?',
            'title' => 'Take the next step with confidence.',
            'description' => 'Explore verified instructors or create an account when you are ready to begin your learning journey.',
        ],
        request()->routeIs('blog.*') => [
            'eyebrow' => 'Turn insight into progress',
            'title' => 'Put what you learned into practice.',
            'description' => 'Find an instructor who can turn useful ideas into a clear, personal learning plan.',
        ],
        request()->routeIs('instructor.*') => [
            'eyebrow' => 'Share expertise · Create progress',
            'title' => 'Your teaching experience can shape someone’s future.',
            'description' => 'Join a structured platform designed for trusted instructors and meaningful one-to-one learning.',
        ],
        default => [
            'eyebrow' => 'Personal · Flexible · Connected',
            'title' => 'Every meaningful learning journey starts with the right connection.',
            'description' => 'Find an instructor who understands your goals and build a clearer path from your first lesson to visible progress.',
        ],
    };
@endphp

<section class="group/section relative isolate overflow-hidden bg-[#17134a] text-white" aria-labelledby="pre-footer-cta-title" data-pre-footer-cta>
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_12%_20%,rgba(236,72,153,0.38),transparent_34%),radial-gradient(circle_at_88%_80%,rgba(34,211,238,0.25),transparent_30%),linear-gradient(115deg,#312e81_0%,#7c3aed_48%,#2563eb_100%)]" aria-hidden="true"></div>
    <div class="pointer-events-none absolute inset-0 opacity-[0.13]" style="background-image:radial-gradient(white .7px,transparent .7px);background-size:22px 22px" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -left-20 top-1/2 h-52 w-52 -translate-y-1/2 rounded-full border border-white/20" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full border border-white/15" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -left-1/4 top-0 h-px w-1/2 bg-gradient-to-r from-transparent via-white/80 to-transparent" aria-hidden="true"></div>

    <div class="relative mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-14 lg:px-8" data-pre-footer-content>
        <div class="grid items-center gap-8 lg:grid-cols-[1fr_auto] lg:gap-14">
            <div class="max-w-3xl">
                <p class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-4 py-2 text-[11px] font-black uppercase tracking-[0.2em] text-cyan-100 backdrop-blur-sm">
                    <span class="relative flex h-2 w-2" aria-hidden="true">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-cyan-300 opacity-60"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-cyan-200"></span>
                    </span>
                    {{ $content['eyebrow'] }}
                </p>
                <h2 id="pre-footer-cta-title" class="mt-5 text-3xl font-black leading-tight tracking-[-0.035em] sm:text-4xl lg:text-5xl">{{ $content['title'] }}</h2>
                <p class="mt-4 max-w-2xl text-base leading-7 text-indigo-100 sm:text-lg">{{ $content['description'] }}</p>
            </div>

            <div class="flex flex-wrap gap-3 lg:w-72 lg:justify-end">
                @if(Route::has('instructors.index'))
                    <a href="{{ route('instructors.index') }}" class="group relative inline-flex min-h-13 items-center justify-center gap-3 overflow-hidden rounded-xl bg-white px-6 text-sm font-black text-indigo-800 shadow-xl shadow-indigo-950/20 transition duration-300 hover:-translate-y-1 hover:shadow-2xl focus:outline-none focus-visible:ring-4 focus-visible:ring-white/50 lg:w-full">
                        <span class="absolute inset-y-0 -left-1/2 w-1/3 -skew-x-12 bg-gradient-to-r from-transparent via-indigo-100/80 to-transparent transition duration-700 group-hover:left-[125%]" aria-hidden="true"></span>
                        <span class="relative">Explore instructors</span>
                        <span class="relative flex h-7 w-7 items-center justify-center rounded-full bg-indigo-50 transition duration-300 group-hover:translate-x-1 group-hover:bg-indigo-100" aria-hidden="true">→</span>
                    </a>
                @endif
                @guest
                    @if(Route::has('auth.register'))
                        <a href="{{ route('auth.register') }}" class="group inline-flex min-h-13 items-center justify-center gap-2 rounded-xl border border-white/30 bg-white/10 px-6 text-sm font-black text-white backdrop-blur-sm transition duration-300 hover:-translate-y-1 hover:border-white/50 hover:bg-white/20 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/30 lg:w-full">Create free account <span class="opacity-0 transition duration-300 group-hover:translate-x-1 group-hover:opacity-100" aria-hidden="true">→</span></a>
                    @endif
                @else
                    @if(Route::has('instructor.apply'))
                        <a href="{{ route('instructor.apply') }}" class="group inline-flex min-h-13 items-center justify-center gap-2 rounded-xl border border-white/30 bg-white/10 px-6 text-sm font-black text-white backdrop-blur-sm transition duration-300 hover:-translate-y-1 hover:border-white/50 hover:bg-white/20 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/30 lg:w-full">Become an instructor <span class="opacity-0 transition duration-300 group-hover:translate-x-1 group-hover:opacity-100" aria-hidden="true">→</span></a>
                    @endif
                @endguest
            </div>
        </div>

        <div class="mt-8 grid gap-2 border-t border-white/15 pt-5 text-xs font-bold uppercase tracking-[0.12em] text-indigo-100/90 sm:grid-cols-3">
            <span class="inline-flex items-center gap-3 rounded-xl px-3 py-2.5 transition duration-300 hover:bg-white/10 hover:text-white"><span class="flex h-7 w-7 items-center justify-center rounded-lg bg-cyan-300/15 text-cyan-200 ring-1 ring-cyan-200/20" aria-hidden="true">✓</span> Verified instructors</span>
            <span class="inline-flex items-center gap-3 rounded-xl px-3 py-2.5 transition duration-300 hover:bg-white/10 hover:text-white"><span class="flex h-7 w-7 items-center justify-center rounded-lg bg-fuchsia-300/15 text-fuchsia-200 ring-1 ring-fuchsia-200/20" aria-hidden="true">✓</span> Flexible scheduling</span>
            <span class="inline-flex items-center gap-3 rounded-xl px-3 py-2.5 transition duration-300 hover:bg-white/10 hover:text-white"><span class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-300/15 text-emerald-200 ring-1 ring-emerald-200/20" aria-hidden="true">✓</span> Secure learning workspace</span>
        </div>
    </div>
</section>
