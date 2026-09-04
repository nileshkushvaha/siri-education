@extends('layouts.frontend')

@section('title', $seo['title'] ?? 'Search')
@section('meta_description', $seo['description'] ?? '')

@push('meta')
    <meta name="robots" content="{{ $seo['robots'] ?? 'noindex, follow' }}">
    @if($seo['canonical'] ?? false)
        <link rel="canonical" href="{{ $seo['canonical'] }}">
    @endif
    <meta property="og:title" content="{{ $seo['og_title'] ?? ($seo['title'] ?? 'Search') }}">
    <meta property="og:description" content="{{ $seo['og_description'] ?? '' }}">
    <meta property="og:url" content="{{ $seo['og_url'] ?? '' }}">
    <meta property="og:type" content="website">
@endpush

@section('content')

{{-- ── Page Hero + Search Box ── --}}
<div class="relative overflow-hidden" style="background: linear-gradient(135deg, #06080f 0%, #0e0b1f 40%, #08101e 100%)">
    <div class="absolute inset-0 pointer-events-none overflow-hidden">
        <div class="absolute -top-32 right-0 w-96 h-96 bg-indigo-600/15 rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 -left-20 w-64 h-64 bg-violet-700/10 rounded-full blur-3xl"></div>
    </div>
    <div class="relative max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-16 lg:py-20">
        <div class="text-center animate-fade-in-up">
            <h1 class="text-4xl lg:text-5xl font-bold tracking-tight mb-6">
                <span class="text-white">Search</span>
                <span class="gradient-text"> Everything</span>
            </h1>

            <form action="{{ route('search.index') }}" method="GET">
                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                        <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                        </svg>
                    </div>
                    <label for="q" class="sr-only">Search query</label>
                    <input
                        id="q"
                        name="q"
                        type="text"
                        value="{{ $query }}"
                        placeholder="Search pages, posts, topics…"
                        autofocus
                        class="w-full pl-12 pr-4 py-4 rounded-xl bg-white/[0.06] border border-white/[0.10] text-white placeholder-slate-500 text-base focus:outline-none focus:ring-2 focus:ring-indigo-500/60 focus:border-indigo-500/40 focus:bg-white/[0.08] transition-all"
                    >
                    @if($query !== '')
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4">
                        <span class="text-xs text-slate-400 bg-white/[0.06] px-2 py-0.5 rounded">{{ $totalResults }} result{{ $totalResults !== 1 ? 's' : '' }}</span>
                    </div>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ── Results ── --}}
<main class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-10 text-slate-900">

    @if($query !== '' && $totalResults === 0)
        <div class="text-center py-16 animate-fade-in-up">
            <div class="mx-auto mb-4 h-16 w-16 rounded-2xl bg-slate-100 ring-1 ring-slate-200 flex items-center justify-center">
                <svg class="h-8 w-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
            </div>
            <p class="text-slate-700 font-semibold">No results for "<span class="text-slate-950">{{ $query }}</span>"</p>
            <p class="text-slate-500 text-sm mt-1">Try different keywords or browse the blog.</p>
            <a href="{{ route('blog.index') }}" class="mt-6 inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-indigo-600 border border-indigo-200 hover:bg-indigo-50 transition-all">
                Browse blog
            </a>
        </div>
    @else
        <div class="space-y-10 animate-fade-in-up">

            {{-- Pages section --}}
            @if($results['pages']->isNotEmpty())
            <section>
                <div class="flex items-center gap-3 mb-5">
                    <h2 class="text-xs font-bold text-slate-500 uppercase tracking-[0.14em]">Pages</h2>
                    <div class="flex-1 h-px bg-slate-200"></div>
                    <span class="text-xs font-semibold text-slate-600 bg-slate-100 px-2 py-0.5 rounded-full">{{ $results['pages']->count() }}</span>
                </div>
                <div class="space-y-2">
                    @foreach($results['pages'] as $page)
                    <a href="{{ $page->slug === 'home' ? route('home') : route('page.show', $page->slug) }}"
                       class="group flex items-start gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm shadow-slate-900/[0.03] hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-lg hover:shadow-indigo-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 transition-all">
                        <div class="mt-0.5 h-8 w-8 rounded-lg bg-indigo-50 ring-1 ring-indigo-100 flex items-center justify-center flex-shrink-0">
                            <svg class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-slate-900 text-base group-hover:text-indigo-600 transition-colors truncate">{{ $page->title }}</p>
                            <p class="text-xs font-medium text-indigo-500/80 mt-0.5">/{{ $page->slug }}</p>
                            @if($page->excerpt)
                                <p class="text-sm text-slate-600 mt-1.5 line-clamp-2 leading-6">{{ $page->excerpt }}</p>
                            @endif
                        </div>
                        <svg class="h-4 w-4 text-slate-300 group-hover:text-indigo-500 group-hover:translate-x-0.5 transition-all flex-shrink-0 mt-1" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </a>
                    @endforeach
                </div>
            </section>
            @endif

            {{-- Posts section --}}
            @if($results['posts']->isNotEmpty())
            <section>
                <div class="flex items-center gap-3 mb-5">
                    <h2 class="text-xs font-bold text-slate-500 uppercase tracking-[0.14em]">Blog Posts</h2>
                    <div class="flex-1 h-px bg-slate-200"></div>
                    <span class="text-xs font-semibold text-slate-600 bg-slate-100 px-2 py-0.5 rounded-full">{{ $results['posts']->count() }}</span>
                </div>
                <div class="space-y-2">
                    @foreach($results['posts'] as $post)
                    <a href="{{ route('blog.show', $post->slug) }}"
                       class="group flex items-start gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm shadow-slate-900/[0.03] hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-lg hover:shadow-indigo-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 transition-all">
                        <div class="mt-0.5 h-8 w-8 rounded-lg bg-violet-50 ring-1 ring-violet-100 flex items-center justify-center flex-shrink-0">
                            <svg class="h-4 w-4 text-violet-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 01-2.25 2.25M16.5 7.5V18a2.25 2.25 0 002.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 002.25 2.25h13.5M6 7.5h3v3H6v-3z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-slate-900 text-base group-hover:text-indigo-600 transition-colors truncate">{{ $post->title }}</p>
                            <p class="text-xs font-medium text-indigo-500/80 mt-0.5">/blog/{{ $post->slug }}</p>
                            @if($post->excerpt)
                                <p class="text-sm text-slate-600 mt-1.5 line-clamp-2 leading-6">{{ $post->excerpt }}</p>
                            @endif
                        </div>
                        <svg class="h-4 w-4 text-slate-300 group-hover:text-indigo-500 group-hover:translate-x-0.5 transition-all flex-shrink-0 mt-1" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </a>
                    @endforeach
                </div>
            </section>
            @endif

            {{-- Empty state when query is empty --}}
            @if($query === '')
            <div class="text-center py-8 text-slate-500 text-sm">
                Start typing to search pages and posts.
            </div>
            @endif
        </div>
    @endif

</main>
@endsection
