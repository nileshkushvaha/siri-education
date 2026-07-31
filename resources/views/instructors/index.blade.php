@extends('layouts.frontend')

@section('title', 'Find an Instructor — '.config('app.name'))

@push('meta')
    <meta name="description" content="Discover verified instructors by subject, academic level, teaching language, location, timezone, and availability.">
    <meta property="og:title" content="Find an Instructor — {{ config('app.name') }}">
    <meta property="og:description" content="Compare approved public instructor profiles and find the right match for your learning goals.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('instructors.index') }}">
@endpush

@section('page-flash', true)

@php
    $activeFilterCount = collect(['q', 'subject', 'topic', 'academic_level', 'language', 'country', 'timezone', 'available'])
        ->filter(fn (string $filter): bool => request()->filled($filter))
        ->count();
@endphp

@section('content')
<main class="overflow-hidden bg-white text-slate-900" data-instructor-marketplace>
    <section class="relative overflow-hidden border-b border-white/10 bg-[#080b24] pb-28 pt-12 text-white sm:pb-32 sm:pt-16" aria-labelledby="instructor-directory-heading">
        <div class="pointer-events-none absolute -left-32 top-0 h-96 w-96 rounded-full bg-cyan-400/20 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute left-[42%] top-10 h-72 w-72 rounded-full bg-violet-500/20 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -right-32 bottom-0 h-[28rem] w-[28rem] rounded-full bg-fuchsia-500/20 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-0 opacity-20" style="background-image:radial-gradient(#a5b4fc .7px,transparent .7px);background-size:24px 24px" aria-hidden="true"></div>

        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <x-ui.breadcrumb :items="[
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Instructors'],
            ]" />

            @if(session()->has('success') || session()->has('error') || session()->has('warning') || session()->has('info'))
                <div class="mt-7 space-y-3" data-marketplace-reveal>
                    @foreach(['success' => 'border-emerald-300/25 bg-emerald-300/10 text-emerald-100', 'error' => 'border-red-300/25 bg-red-300/10 text-red-100', 'warning' => 'border-amber-300/25 bg-amber-300/10 text-amber-100', 'info' => 'border-cyan-300/25 bg-cyan-300/10 text-cyan-100'] as $flashType => $flashClasses)
                        @if(session($flashType))
                            <div class="rounded-2xl border px-4 py-3 text-sm {{ $flashClasses }}" role="alert">{{ session($flashType) }}</div>
                        @endif
                    @endforeach
                </div>
            @endif

            <div class="mt-10 grid items-end gap-10 lg:grid-cols-[1.15fr_.85fr]" data-marketplace-reveal>
                <div>
                    <span class="inline-flex items-center gap-2 rounded-full border border-cyan-300/20 bg-cyan-300/10 px-4 py-2 text-xs font-black uppercase tracking-[0.16em] text-cyan-200">
                        <span class="h-2 w-2 rounded-full bg-emerald-400" aria-hidden="true"></span>
                        Approved public profiles
                    </span>
                    <h1 id="instructor-directory-heading" class="mt-6 max-w-4xl text-4xl font-black tracking-tight sm:text-5xl lg:text-[3.75rem] lg:leading-[1.04]">Find expertise that fits your goals, schedule, and learning style</h1>
                    <p class="mt-6 max-w-3xl text-lg leading-8 text-slate-300">Compare subject coverage, academic levels, teaching languages, experience, location, and visible availability before deciding who is right for you.</p>
                    <div class="mt-7 flex flex-wrap gap-x-6 gap-y-3 text-sm font-bold text-slate-300">
                        @foreach(['Verified profile signals', 'Timezone-aware discovery', 'Transparent experience and feedback'] as $point)
                            <span class="inline-flex items-center gap-2"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-400/15 text-emerald-300" aria-hidden="true">✓</span>{{ $point }}</span>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <article class="rounded-3xl border border-white/10 bg-white/[0.07] p-6 backdrop-blur">
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-400 to-blue-500 text-lg font-black" aria-hidden="true">{{ $instructors->total() }}</span>
                        <p class="mt-5 text-2xl font-black">{{ $instructors->total() }}</p>
                        <p class="mt-1 text-sm leading-6 text-slate-300">{{ Str::plural('public instructor', $instructors->total()) }} matching this view</p>
                    </article>
                    <article class="rounded-3xl border border-white/10 bg-gradient-to-br from-violet-500/20 to-fuchsia-500/10 p-6 backdrop-blur">
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-500 text-lg font-black" aria-hidden="true">{{ $filters['subjects']->count() }}</span>
                        <p class="mt-5 text-2xl font-black">{{ $filters['subjects']->count() }}</p>
                        <p class="mt-1 text-sm leading-6 text-slate-300">{{ Str::plural('subject', $filters['subjects']->count()) }} with active public coverage</p>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section class="relative z-10 bg-gradient-to-b from-[#f5f7ff] to-white pb-8" aria-labelledby="marketplace-filter-heading">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <form method="GET" action="{{ route('instructors.index') }}" class="marketplace-filter-form -translate-y-16 overflow-hidden rounded-[2rem] border border-indigo-200/80 bg-gradient-to-br from-white via-indigo-50/40 to-cyan-50/60 p-4 shadow-2xl shadow-indigo-200/50 sm:p-5" data-marketplace-reveal>
                <div class="flex flex-col gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.15em] text-indigo-600">Personalise discovery</p>
                        <h2 id="marketplace-filter-heading" class="mt-1.5 text-xl font-black text-slate-950">Build a useful shortlist</h2>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($activeFilterCount > 0)
                            <a href="{{ route('instructors.index') }}" class="rounded-full border border-violet-100 bg-violet-50 px-3 py-1.5 text-xs font-black text-violet-700 transition hover:-translate-y-0.5 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-100">Clear {{ $activeFilterCount }} {{ Str::plural('filter', $activeFilterCount) }}</a>
                        @endif
                        <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-black text-emerald-700">{{ $instructors->total() }} {{ Str::plural('match', $instructors->total()) }}</span>
                    </div>
                </div>

                <div class="marketplace-filter-controls mt-4 grid gap-3 lg:grid-cols-[minmax(0,1.45fr)_minmax(11rem,.8fr)_minmax(11rem,.8fr)_auto] lg:items-end">
                    <div class="marketplace-filter-field"><x-ui.input name="q" label="Search instructors" value="{{ request('q') }}" placeholder="Name, subject, expertise, or teaching focus" /></div>
                    <div class="marketplace-filter-field">
                        <x-ui.select name="subject" label="Subject"><option value="">All subjects</option>@foreach($filters['subjects'] as $subject)<option value="{{ $subject['value'] }}" @selected(request('subject') === $subject['value'])>{{ $subject['label'] }}</option>@endforeach</x-ui.select>
                    </div>
                    <div class="marketplace-filter-field">
                        <x-ui.select name="topic" label="Topic"><option value="">All topics</option>@foreach($filters['topics'] as $topic)<option value="{{ $topic['value'] }}" @selected(request('topic') === $topic['value'])>{{ $topic['label'] }}</option>@endforeach</x-ui.select>
                    </div>
                    <div class="marketplace-filter-action">
                        <button type="submit" class="group relative inline-flex min-h-12 w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-600 px-6 text-sm font-black whitespace-nowrap text-white shadow-lg shadow-indigo-200 transition duration-300 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-violet-200 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 lg:w-auto">
                            <span class="absolute inset-y-0 -left-1/2 w-1/3 -skew-x-12 bg-gradient-to-r from-transparent via-white/30 to-transparent transition-all duration-700 group-hover:left-[125%]" aria-hidden="true"></span>
                            <span class="relative">Apply filters</span><span class="relative transition-transform duration-300 group-hover:translate-x-1" aria-hidden="true">→</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <section class="bg-white pb-16 sm:pb-20" aria-labelledby="marketplace-results-heading">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between" data-marketplace-reveal>
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.15em] text-cyan-700">Instructor marketplace</p>
                    <h2 id="marketplace-results-heading" class="mt-2 text-3xl font-black tracking-tight text-slate-950">Profiles ready to explore</h2>
                    <p class="mt-3 max-w-2xl leading-7 text-slate-600">Open a profile to review teaching background, approved subject coverage, public feedback, and available next steps.</p>
                </div>
                <p class="text-sm font-bold text-slate-500">Showing {{ $instructors->firstItem() ?? 0 }}–{{ $instructors->lastItem() ?? 0 }} of <strong class="text-slate-950">{{ $instructors->total() }}</strong></p>
            </div>

            @if(request('subject'))
                @if($pricingCountryContext->country)
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <span>
                            Showing lesson prices for <strong class="text-slate-700">{{ $pricingCountryContext->country->name }}</strong>
                            @if($pricingCountryContext->isGuestDefault)(default)@endif
                        </span>
                        @if($pricingCountryContext->isGuestDefault && $pricingCountries->isNotEmpty())
                            <form method="GET" class="inline-flex items-center gap-1.5">
                                @foreach(request()->except('pricing_country') as $key => $value)
                                    @if(is_array($value))
                                        @foreach($value as $v)<input type="hidden" name="{{ $key }}[]" value="{{ $v }}">@endforeach
                                    @else
                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                    @endif
                                @endforeach
                                <label for="pricing_country" class="sr-only">Change pricing country</label>
                                <select id="pricing_country" name="pricing_country" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-indigo-700">
                                    @foreach($pricingCountries as $c)
                                        <option value="{{ $c->iso2 }}" @selected($c->id === $pricingCountryContext->country->id)>{{ $c->name }}</option>
                                    @endforeach
                                </select>
                            </form>
                        @endif
                    </div>
                @elseif(! $pricingCountryContext->isAuthenticatedStudent)
                    <p class="mt-3 text-xs text-slate-500">Select your billing country to view prices.</p>
                @endif
            @else
                <p class="mt-3 text-xs text-slate-500">Choose a subject above to see localized lesson prices on each card.</p>
            @endif

            @if($instructors->isEmpty())
                <div class="mt-8 rounded-[2rem] border border-dashed border-indigo-200 bg-gradient-to-br from-indigo-50 via-white to-cyan-50 p-10 text-center shadow-sm sm:p-14" data-marketplace-reveal>
                    <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-100 text-2xl font-black text-indigo-700" aria-hidden="true">⌕</span>
                    <h3 class="mt-5 text-xl font-black text-slate-950">No instructors found</h3>
                    <p class="mx-auto mt-3 max-w-md leading-7 text-slate-600">Try removing one or two filters, choosing a broader subject, or searching with fewer words.</p>
                    <a href="{{ route('instructors.index') }}" class="mt-6 inline-flex min-h-11 items-center rounded-xl bg-slate-950 px-5 text-sm font-black text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200">Reset discovery</a>
                </div>
            @else
                <div class="mt-9 grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3" data-marketplace-grid>
                    @foreach($instructors as $instructor)
                        <div data-marketplace-card><x-instructor.card :instructor="$instructor" /></div>
                    @endforeach
                </div>

                @if($instructors->hasPages())
                    <div class="mt-10 rounded-2xl border border-slate-200 bg-slate-50 p-4" data-marketplace-reveal><x-ui.pagination :paginator="$instructors" /></div>
                @endif
            @endif
        </div>
    </section>

</main>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const page = document.querySelector('[data-instructor-marketplace]');
    if (! page || window.matchMedia('(prefers-reduced-motion: reduce)').matches || ! ('IntersectionObserver' in window)) return;

    page.classList.add('marketplace-motion-ready');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (! entry.isIntersecting) return;
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -7% 0px', threshold: 0.1 });

    page.querySelectorAll('[data-marketplace-reveal], [data-marketplace-card]').forEach((element, index) => {
        element.style.setProperty('--marketplace-delay', `${Math.min((index % 3) * 90, 180)}ms`);
        observer.observe(element);
    });
});
</script>
@endpush
@endsection
