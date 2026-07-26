@extends('layouts.frontend')

@section('title', '#' . $tag->name . ' — Blog')
@section('meta_description', $tag->description ?? 'Browse posts tagged with ' . $tag->name . '.')

@push('meta')
    <meta property="og:type" content="website">
    <meta property="og:title" content="#{{ $tag->name }} — Blog">
    <meta property="og:description" content="{{ $tag->description ?? 'Browse posts tagged with ' . $tag->name . '.' }}">
    <meta property="og:url" content="{{ route('blog.tag', $tag->slug) }}">
    <link rel="canonical" href="{{ route('blog.tag', $tag->slug) }}">
@endpush

@section('content')

{{-- ── Page Hero ── --}}
<div class="relative overflow-hidden" style="background: linear-gradient(135deg, #06080f 0%, #0e0b1f 40%, #08101e 100%)">
    <div class="absolute inset-0 pointer-events-none overflow-hidden">
        <div class="absolute -top-32 right-0 w-96 h-96 bg-violet-600/12 rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 -left-20 w-64 h-64 bg-indigo-700/10 rounded-full blur-3xl"></div>
    </div>
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 lg:py-20">
        <div class="animate-fade-in-up">
            <div class="flex items-center gap-2 text-sm text-slate-400 mb-4">
                <a href="{{ url('/') }}" class="hover:text-slate-400 transition-colors">Home</a>
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                <a href="{{ route('blog.index') }}" class="hover:text-slate-400 transition-colors">Blog</a>
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                <span>Tag</span>
            </div>
            <h1 class="text-4xl lg:text-5xl font-bold tracking-tight">
                <span class="text-slate-400 font-light">#</span><span class="gradient-text">{{ $tag->name }}</span>
            </h1>
            @if($tag->description)
                <p class="mt-3 text-lg text-slate-400 max-w-2xl">{{ $tag->description }}</p>
            @endif
            <div class="mt-4 inline-flex items-center gap-2 text-sm text-slate-400">
                <span class="h-1.5 w-1.5 rounded-full bg-violet-500"></span>
                {{ $posts->total() }} {{ Str::plural('article', $posts->total()) }}
            </div>
        </div>
    </div>
</div>

{{-- ── Posts grid ── --}}
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    @if($posts->isEmpty())
        <div class="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-16 text-center animate-fade-in-up">
            <p class="text-slate-400 font-medium">No posts with this tag yet.</p>
            <a href="{{ route('blog.index') }}" class="mt-4 inline-flex items-center gap-2 text-sm text-indigo-400 hover:text-indigo-300 transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
                Back to blog
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach($posts as $index => $post)
                <article
                    class="group relative rounded-2xl border border-white/[0.06] bg-white/[0.02] overflow-hidden transition-all duration-300 hover:-translate-y-1 card-glow animate-fade-in-up"
                    style="animation-delay: {{ min($index * 0.07, 0.35) }}s"
                >
                    @if($post->featured_image_url)
                        <div class="overflow-hidden aspect-video bg-slate-900">
                            <img src="{{ $post->featured_image_url }}" alt="{{ $post->title }}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105" loading="lazy">
                        </div>
                    @else
                        <div class="aspect-video bg-gradient-to-br from-violet-900/30 to-indigo-900/20"></div>
                    @endif

                    <div class="p-6">
                        @if($post->categories->isNotEmpty())
                        <div class="mb-3 flex flex-wrap gap-1.5">
                            @foreach($post->categories->take(2) as $cat)
                                <a href="{{ route('blog.category', $cat->slug) }}" class="inline-flex items-center rounded-full bg-indigo-500/10 border border-indigo-500/20 px-2.5 py-0.5 text-xs font-medium text-indigo-400 hover:bg-indigo-500/20 transition-all">
                                    {{ $cat->name }}
                                </a>
                            @endforeach
                        </div>
                        @endif
                        <h2 class="text-base font-semibold text-white leading-snug line-clamp-2 group-hover:text-indigo-300 transition-colors">
                            <a href="{{ route('blog.show', $post->slug) }}" class="after:absolute after:inset-0">{{ $post->title }}</a>
                        </h2>
                        @if($post->excerpt)
                            <p class="mt-2 text-sm text-slate-400 line-clamp-2 leading-relaxed">{{ $post->excerpt }}</p>
                        @endif
                        <div class="mt-4 flex items-center gap-2 text-xs text-slate-400">
                            @if($post->author)
                                <div class="h-5 w-5 rounded-full bg-indigo-600/30 flex items-center justify-center flex-shrink-0">
                                    <span class="text-[10px] font-bold text-indigo-300">{{ mb_substr($post->author->name, 0, 1) }}</span>
                                </div>
                                <span>{{ $post->author->name }}</span>
                                <span class="text-slate-700">·</span>
                            @endif
                            <span>{{ max(1, (int) $post->reading_time) }} min read</span>
                            @if($post->published_at)
                                <span class="text-slate-700">·</span>
                                <time datetime="{{ $post->published_at->toISOString() }}">{{ $post->published_at->format('M j, Y') }}</time>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        @if($posts->hasPages())
            <div class="mt-12 flex justify-center">{{ $posts->links() }}</div>
        @endif
    @endif
</main>

@endsection
