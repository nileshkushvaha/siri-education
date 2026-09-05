@extends('layouts.frontend')

@section('title', 'Blog — ' . (app(\App\Settings\GeneralSettings::class)->app_name ?? config('app.name')))
@section('meta_description', 'Read the latest articles, guides, and updates from our blog.')

@push('meta')
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('blog.index') }}">
@endpush

@section('content')

{{-- ── Page Hero ── --}}
<div class="relative overflow-hidden" style="background: linear-gradient(135deg, #06080f 0%, #0e0b1f 40%, #08101e 100%)">
    <div class="absolute inset-0 pointer-events-none overflow-hidden">
        <div class="absolute -top-32 right-0 w-96 h-96 bg-indigo-600/15 rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 -left-20 w-64 h-64 bg-violet-700/10 rounded-full blur-3xl"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full h-px bg-gradient-to-r from-transparent via-indigo-500/10 to-transparent"></div>
    </div>
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 lg:py-20">
        <div class="animate-fade-in-up">
            <div class="flex items-center gap-2 text-sm text-slate-400 mb-4">
                <a href="{{ url('/') }}" class="hover:text-slate-400 transition-colors">Home</a>
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                <span>Blog</span>
            </div>
            <h1 class="text-4xl lg:text-5xl font-bold tracking-tight">
                <span class="text-white">Latest</span>
                <span class="gradient-text"> Articles</span>
            </h1>
            <p class="mt-4 text-lg text-slate-400 max-w-2xl">
                Guides, tutorials, and insights to help you learn and grow.
            </p>
            @if($posts->total() > 0)
            <div class="mt-4 inline-flex items-center gap-2 text-sm text-slate-400">
                <span class="h-1.5 w-1.5 rounded-full bg-indigo-500"></span>
                {{ $posts->total() }} {{ Str::plural('article', $posts->total()) }}
            </div>
            @endif
        </div>
    </div>
</div>

{{-- ── Posts grid ── --}}
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    @if($posts->isEmpty())
        <div class="rounded-2xl border border-white/[0.06] bg-white/[0.02] p-16 text-center animate-fade-in-up">
            <div class="mx-auto mb-4 h-16 w-16 rounded-2xl bg-indigo-500/10 flex items-center justify-center">
                <svg class="h-8 w-8 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 01-2.25 2.25M16.5 7.5V18a2.25 2.25 0 002.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 002.25 2.25h13.5M6 7.5h3v3H6v-3z"/></svg>
            </div>
            <p class="text-slate-400 font-medium">No posts published yet.</p>
            <p class="text-slate-400 text-sm mt-1">Check back soon for fresh content.</p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach($posts as $index => $post)
                <article
                    class="group relative rounded-2xl border border-white/[0.06] bg-white/[0.02] overflow-hidden transition-all duration-300 hover:-translate-y-1 card-glow animate-fade-in-up"
                    style="animation-delay: {{ min($index * 0.07, 0.35) }}s"
                >
                    {{-- Featured image --}}
                    @if($post->featured_image_url)
                        <div class="overflow-hidden aspect-video bg-slate-900">
                            <img
                                src="{{ $post->featured_image_url }}"
                                alt="{{ $post->title }}"
                                class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                                loading="lazy"
                            >
                        </div>
                    @else
                        <div class="aspect-video bg-gradient-to-br from-indigo-900/30 to-violet-900/20 flex items-center justify-center">
                            <svg class="h-12 w-12 text-indigo-800/50" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 01-2.25 2.25M16.5 7.5V18a2.25 2.25 0 002.25 2.25h13.5M6 7.5h3v3H6v-3z"/></svg>
                        </div>
                    @endif

                    <div class="p-6">
                        {{-- Categories --}}
                        @if($post->categories->isNotEmpty())
                        <div class="mb-3 flex flex-wrap gap-1.5">
                            @foreach($post->categories->take(2) as $cat)
                                <a href="{{ route('blog.category', $cat->slug) }}"
                                   class="inline-flex items-center rounded-full bg-indigo-500/10 border border-indigo-500/20 px-2.5 py-0.5 text-xs font-medium text-indigo-400 hover:bg-indigo-500/20 hover:border-indigo-500/30 transition-all">
                                    {{ $cat->name }}
                                </a>
                            @endforeach
                        </div>
                        @endif

                        <h2 class="text-base font-semibold text-white leading-snug line-clamp-2 group-hover:text-indigo-300 transition-colors">
                            <a href="{{ route('blog.show', $post->slug) }}" class="after:absolute after:inset-0">
                                {{ $post->title }}
                            </a>
                        </h2>

                        @php $cardSummary = $post->excerpt ?: (filled($post->content) ? \Illuminate\Support\Str::limit(strip_tags($post->content), 140) : null); @endphp
                        @if($cardSummary)
                            <p class="mt-2 text-sm text-slate-400 line-clamp-2 leading-relaxed">{{ $cardSummary }}</p>
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

                        {{-- Tags --}}
                        @if($post->tags->isNotEmpty())
                        <div class="mt-3 flex flex-wrap gap-1">
                            @foreach($post->tags->take(3) as $tag)
                                <a href="{{ route('blog.tag', $tag->slug) }}"
                                   class="inline-flex text-xs text-slate-400 hover:text-slate-400 transition-colors">
                                    #{{ $tag->name }}
                                </a>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        @if($posts->hasPages())
            <div class="mt-12 flex justify-center animate-fade-in">
                <div class="[&_.pagination]:flex [&_.pagination]:items-center [&_.pagination]:gap-1">
                    {{ $posts->links() }}
                </div>
            </div>
        @endif
    @endif
</main>

@endsection
