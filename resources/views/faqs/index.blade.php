@extends('layouts.frontend')

@section('title', 'Help Center — '.config('app.name'))

@push('meta')
    <meta name="description" content="Find clear answers about accounts, instructor verification, lesson bookings, payments, privacy, and support at {{ config('app.name') }}.">
@endpush

@php
    $activeCategory = $categories->firstWhere('id', $categoryId);
    $categoryPalettes = [
        'border-indigo-200 bg-indigo-50 text-indigo-700',
        'border-cyan-200 bg-cyan-50 text-cyan-700',
        'border-rose-200 bg-rose-50 text-rose-700',
        'border-emerald-200 bg-emerald-50 text-emerald-700',
        'border-amber-200 bg-amber-50 text-amber-700',
        'border-fuchsia-200 bg-fuchsia-50 text-fuchsia-700',
    ];
@endphp

@section('content')
<main class="overflow-hidden bg-white text-slate-900" data-public-faqs>
    <section class="relative overflow-hidden border-b border-white/10 bg-[#090c2a] py-16 text-white sm:py-20" aria-labelledby="faq-page-heading">
        <div class="pointer-events-none absolute -left-28 top-0 h-80 w-80 rounded-full bg-cyan-400/20 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute right-[15%] top-8 h-72 w-72 rounded-full bg-violet-500/25 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -right-32 bottom-0 h-96 w-96 rounded-full bg-fuchsia-500/20 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-0 opacity-20" style="background-image:radial-gradient(#a5b4fc .7px,transparent .7px);background-size:24px 24px" aria-hidden="true"></div>

        <div class="relative mx-auto grid max-w-7xl items-end gap-10 px-4 sm:px-6 lg:grid-cols-[.85fr_1.15fr] lg:px-8">
            <div data-faq-reveal>
                <span class="inline-flex items-center gap-2 rounded-full border border-cyan-300/20 bg-cyan-300/10 px-4 py-2 text-xs font-black uppercase tracking-[0.16em] text-cyan-200">
                    <span class="h-2 w-2 rounded-full bg-emerald-400" aria-hidden="true"></span>
                    Public help center
                </span>
                <h1 id="faq-page-heading" class="mt-6 text-4xl font-black tracking-tight sm:text-5xl lg:text-[3.5rem] lg:leading-[1.05]">Answers for a clearer learning journey</h1>
                <p class="mt-5 max-w-2xl text-lg leading-8 text-slate-300">Explore guidance about joining the platform, choosing an instructor, booking lessons, managing payments, and understanding our trust and verification processes.</p>
            </div>

            <div class="rounded-[2rem] border border-white/10 bg-white/[0.07] p-5 shadow-2xl shadow-black/25 backdrop-blur sm:p-6" data-faq-reveal>
                <div class="mb-4 flex items-center justify-between gap-4">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.14em] text-violet-200">Search the knowledge base</p>
                        <p class="mt-1 text-sm text-slate-300">Use a keyword such as booking, wallet, verification, or password.</p>
                    </div>
                    <span class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-fuchsia-500 sm:flex" aria-hidden="true">?</span>
                </div>

                <form action="{{ route('faqs.index') }}" method="GET" class="relative" role="search">
                    @if($categoryId)
                        <input type="hidden" name="category" value="{{ $categoryId }}">
                    @endif
                    <label for="public-faq-search" class="sr-only">Search frequently asked questions</label>
                    <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input id="public-faq-search" type="search" name="q" value="{{ $search }}" placeholder="What can we help you understand?" autocomplete="off" class="min-h-14 w-full rounded-2xl border border-white/15 bg-white px-12 pr-28 text-slate-950 shadow-xl placeholder:text-slate-400 focus:border-cyan-300 focus:outline-none focus:ring-4 focus:ring-cyan-300/15">
                    <button type="submit" class="absolute right-2 top-2 inline-flex min-h-10 items-center rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-5 text-sm font-black text-white transition hover:from-indigo-500 hover:to-violet-500 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/30">Search</button>
                </form>

                @if($search)
                    <div class="mt-3 flex items-center justify-between gap-3 text-sm">
                        <p class="text-slate-300">Showing matches for <strong class="text-white">“{{ $search }}”</strong></p>
                        <a href="{{ route('faqs.index', $categoryId ? ['category' => $categoryId] : []) }}" class="font-black text-cyan-300 hover:text-cyan-200">Clear search</a>
                    </div>
                @endif
            </div>
        </div>
    </section>

    <section class="border-b border-indigo-100 bg-gradient-to-br from-[#f8fbff] via-white to-violet-50 py-10" aria-labelledby="faq-categories-heading">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8" data-faq-reveal>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.15em] text-indigo-600">Browse by topic</p>
                    <h2 id="faq-categories-heading" class="mt-1 text-xl font-black text-slate-950">Find the guidance relevant to you</h2>
                </div>
                <nav class="flex flex-wrap gap-2" aria-label="FAQ categories">
                    <a href="{{ route('faqs.index', $search ? ['q' => $search] : []) }}" class="inline-flex min-h-10 items-center rounded-full border px-4 text-sm font-black transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 {{ !$categoryId ? 'border-slate-950 bg-slate-950 text-white shadow-lg' : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-200 hover:text-indigo-700' }}" @if(!$categoryId) aria-current="page" @endif>All topics</a>
                    @foreach($categories as $index => $category)
                        <a href="{{ route('faqs.index', array_filter(['q' => $search, 'category' => $category->id])) }}" class="inline-flex min-h-10 items-center gap-2 rounded-full border px-4 text-sm font-black transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 {{ $categoryId === $category->id ? $categoryPalettes[$index % count($categoryPalettes)].' shadow-sm' : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-200 hover:text-indigo-700' }}" @if($categoryId === $category->id) aria-current="page" @endif>
                            {{ $category->name }}
                            @if($category->published_faqs_count > 0)<span class="opacity-60">{{ $category->published_faqs_count }}</span>@endif
                        </a>
                    @endforeach
                </nav>
            </div>
        </div>
    </section>

    @if($featured->isNotEmpty())
        <section class="bg-white py-14 sm:py-16" aria-labelledby="featured-faqs-heading">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between" data-faq-reveal>
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.15em] text-amber-600">Start here</p>
                        <h2 id="featured-faqs-heading" class="mt-2 text-3xl font-black tracking-tight text-slate-950">Questions people ask first</h2>
                        <p class="mt-3 max-w-2xl leading-7 text-slate-600">A quick route to important information about access, verification, and using the platform confidently.</p>
                    </div>
                    <span class="w-fit rounded-full border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-black uppercase tracking-wide text-amber-700">Featured guidance</span>
                </div>

                <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5" data-faq-reveal>
                    @foreach($featured as $index => $faq)
                        <a href="#faq-{{ $faq->id }}" data-featured-faq class="group relative overflow-hidden rounded-3xl border border-slate-200 bg-gradient-to-br {{ ['from-indigo-50 to-white','from-cyan-50 to-white','from-amber-50 to-white','from-rose-50 to-white','from-violet-50 to-white'][$index % 5] }} p-5 shadow-sm transition hover:-translate-y-1 hover:border-indigo-200 hover:shadow-xl hover:shadow-indigo-100/60 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100">
                            <span class="text-xs font-black uppercase tracking-[0.13em] text-indigo-600">{{ $faq->category?->name ?? 'General' }}</span>
                            <h3 class="mt-3 text-sm font-black leading-6 text-slate-900 transition group-hover:text-indigo-700">{{ $faq->question }}</h3>
                            <span class="mt-5 inline-flex items-center gap-1 text-xs font-black text-slate-500 group-hover:text-indigo-600">Read answer <span aria-hidden="true">↓</span></span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="border-y border-slate-200 bg-[#f8fbff] py-14 sm:py-20" aria-labelledby="faq-results-heading">
        <div class="mx-auto grid max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-[.68fr_1.32fr] lg:px-8">
            <aside class="lg:sticky lg:top-32 lg:self-start" data-faq-reveal>
                <p class="text-xs font-black uppercase tracking-[0.15em] text-cyan-700">Knowledge base</p>
                <h2 id="faq-results-heading" class="mt-3 text-3xl font-black tracking-tight text-slate-950">
                    @if($activeCategory)
                        {{ $activeCategory->name }} questions
                    @elseif($search)
                        Search results
                    @else
                        Everything you need to know
                    @endif
                </h2>
                <p class="mt-4 leading-7 text-slate-600">Answers are maintained by the platform team so public guidance stays aligned with current account, learning, teaching, and payment workflows.</p>

                <div class="mt-7 rounded-3xl bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 p-6 text-white shadow-xl shadow-indigo-200/60">
                    <p class="text-xs font-black uppercase tracking-[0.14em] text-cyan-100">Need individual help?</p>
                    <h3 class="mt-2 text-xl font-black">Your question deserves a clear answer.</h3>
                    <p class="mt-3 text-sm leading-6 text-indigo-100">Send the support team the relevant account or booking context. Avoid sharing passwords or sensitive payment credentials.</p>
                    <a href="{{ route('page.show', 'contact-us') }}" class="mt-5 inline-flex min-h-11 items-center rounded-xl bg-white px-5 text-sm font-black text-indigo-700 transition hover:-translate-y-0.5 hover:bg-cyan-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/30">Contact support →</a>
                </div>
            </aside>

            <div data-faq-reveal>
                @if($faqs->isEmpty())
                    <div class="rounded-[2rem] border border-dashed border-indigo-200 bg-white p-10 text-center shadow-sm sm:p-14">
                        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-50 text-2xl font-black text-indigo-600" aria-hidden="true">?</span>
                        <h3 class="mt-5 text-xl font-black text-slate-950">No FAQs found</h3>
                        <p class="mx-auto mt-3 max-w-md leading-7 text-slate-600">
                            @if($search)
                                We could not find a public answer matching “{{ $search }}”. Try a shorter keyword or browse all topics.
                            @else
                                No public questions are currently available in this category.
                            @endif
                        </p>
                        <a href="{{ route('faqs.index') }}" class="mt-6 inline-flex min-h-11 items-center rounded-xl bg-slate-950 px-5 text-sm font-black text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200">View all questions</a>
                    </div>
                @else
                    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm font-bold text-slate-500"><strong class="text-slate-950">{{ $faqs->count() }}</strong> {{ Str::plural('public question', $faqs->count()) }}</p>
                        <div class="flex items-center gap-2" aria-label="Accordion controls">
                            <button type="button" data-faq-expand class="min-h-10 rounded-xl border border-indigo-200 bg-indigo-50 px-4 text-xs font-black text-indigo-700 transition hover:bg-indigo-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100">Expand all</button>
                            <button type="button" data-faq-collapse class="min-h-10 rounded-xl border border-slate-200 bg-white px-4 text-xs font-black text-slate-600 transition hover:border-slate-300 hover:text-slate-950 focus:outline-none focus-visible:ring-4 focus-visible:ring-slate-100">Collapse all</button>
                        </div>
                    </div>

                    @php $grouped = $faqs->groupBy(fn ($faq) => $faq->category?->name ?? 'General'); @endphp
                    <div class="space-y-8">
                        @foreach($grouped as $categoryName => $group)
                            <section aria-labelledby="faq-group-{{ Str::slug($categoryName) }}">
                                <div class="mb-4 flex items-center gap-3">
                                    <span class="h-2.5 w-2.5 rounded-full bg-gradient-to-br from-cyan-400 to-indigo-500" aria-hidden="true"></span>
                                    <h3 id="faq-group-{{ Str::slug($categoryName) }}" class="text-sm font-black uppercase tracking-[0.13em] text-slate-500">{{ $categoryName }}</h3>
                                </div>

                                <div class="space-y-3">
                                    @foreach($group as $faq)
                                        <details id="faq-{{ $faq->id }}" class="group scroll-mt-32 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition open:border-indigo-200 open:shadow-lg open:shadow-indigo-100/50">
                                            <summary class="flex min-h-16 cursor-pointer list-none items-center justify-between gap-5 px-5 py-4 font-black leading-6 text-slate-900 transition hover:bg-indigo-50/50 focus:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-indigo-100 sm:px-6">
                                                <span>{{ $faq->question }}</span>
                                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-lg text-indigo-600 transition duration-300 group-open:rotate-45 group-open:bg-indigo-600 group-open:text-white" aria-hidden="true">+</span>
                                            </summary>
                                            <div class="border-t border-indigo-100 bg-gradient-to-br from-white to-indigo-50/40 px-5 py-5 text-sm leading-7 text-slate-600 sm:px-6">
                                                <div class="cms-content max-w-none">{!! $faq->answer !!}</div>
                                            </div>
                                        </details>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>

</main>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const page = document.querySelector('[data-public-faqs]');

    if (! page) return;

    const disclosures = Array.from(page.querySelectorAll('details[id^="faq-"]'));

    page.querySelector('[data-faq-expand]')?.addEventListener('click', () => disclosures.forEach((item) => { item.open = true; }));
    page.querySelector('[data-faq-collapse]')?.addEventListener('click', () => disclosures.forEach((item) => { item.open = false; }));

    disclosures.forEach((item) => {
        const storageKey = `public-${item.id}`;
        item.open = sessionStorage.getItem(storageKey) === 'open' || window.location.hash === `#${item.id}`;
        item.addEventListener('toggle', () => item.open ? sessionStorage.setItem(storageKey, 'open') : sessionStorage.removeItem(storageKey));
    });

    page.querySelectorAll('[data-featured-faq]').forEach((link) => {
        link.addEventListener('click', () => {
            const target = document.querySelector(link.hash);
            if (target instanceof HTMLDetailsElement) target.open = true;
        });
    });

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || ! ('IntersectionObserver' in window)) return;

    page.classList.add('faq-motion-ready');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (! entry.isIntersecting) return;
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });

    page.querySelectorAll('[data-faq-reveal]').forEach((element, index) => {
        element.style.setProperty('--faq-reveal-delay', `${Math.min((index % 3) * 90, 180)}ms`);
        observer.observe(element);
    });
});
</script>
@endpush
@endsection
