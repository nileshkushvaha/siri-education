@extends('layouts.frontend')

@section('title', 'Help Center')

@section('content')
<div class="min-h-screen bg-surface-dark">

    {{-- Hero --}}
    <div class="relative py-20 px-4 text-center" style="background: linear-gradient(180deg, rgba(99,102,241,0.12) 0%, transparent 100%)">
        <div class="max-w-2xl mx-auto">
            <h1 class="text-4xl font-bold text-white mb-3">Help Center</h1>
            <p class="text-slate-400 mb-8">Find answers to frequently asked questions.</p>

            {{-- Search --}}
            <form action="{{ route('faqs.index') }}" method="GET" class="relative" role="search">
                @if($categoryId)
                    <input type="hidden" name="category" value="{{ $categoryId }}">
                @endif
                <input
                    type="search"
                    name="q"
                    value="{{ $search }}"
                    placeholder="Search questions..."
                    autocomplete="off"
                    class="w-full py-4 pl-12 pr-4 rounded-2xl border border-white/10 text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500/50 focus:ring-1 focus:ring-indigo-500/30 transition-all"
                    style="background: rgba(255,255,255,0.05)"
                >
                <svg class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                @if($search)
                    <a href="{{ route('faqs.index', $categoryId ? ['category' => $categoryId] : []) }}"
                       class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-300 transition-colors"
                       title="Clear search">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </a>
                @endif
            </form>
        </div>
    </div>

    <div class="max-w-5xl mx-auto px-4 pb-20">

        {{-- Category filter --}}
        @if($categories->isNotEmpty())
        <div class="flex flex-wrap gap-2 mb-10 justify-center">
            <a href="{{ route('faqs.index', $search ? ['q' => $search] : []) }}"
               class="px-4 py-1.5 rounded-full text-sm font-medium transition-all border
                      {{ !$categoryId ? 'bg-indigo-500/15 text-indigo-300 border-indigo-500/25' : 'text-slate-400 border-white/10 hover:text-white hover:border-white/20' }}">
                All
            </a>
            @foreach($categories as $category)
            <a href="{{ route('faqs.index', array_filter(['q' => $search, 'category' => $category->id])) }}"
               class="px-4 py-1.5 rounded-full text-sm font-medium transition-all border
                      {{ $categoryId === $category->id ? 'bg-indigo-500/15 text-indigo-300 border-indigo-500/25' : 'text-slate-400 border-white/10 hover:text-white hover:border-white/20' }}">
                {{ $category->name }}
                @if($category->published_faqs_count > 0)
                    <span class="ml-1 text-xs opacity-60">({{ $category->published_faqs_count }})</span>
                @endif
            </a>
            @endforeach
        </div>
        @endif

        {{-- Featured FAQs (only when no search / filter active) --}}
        @if($featured->isNotEmpty())
        <div class="mb-12">
            <h2 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-amber-400" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                </svg>
                Featured
            </h2>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach($featured as $faq)
                <div class="rounded-2xl border border-amber-500/15 p-4 hover:border-amber-500/30 transition-colors cursor-pointer"
                     style="background: rgba(245,158,11,0.04)"
                     onclick="document.getElementById('faq-{{ $faq->id }}')?.scrollIntoView({behavior:'smooth',block:'center'})">
                    <p class="text-sm font-medium text-slate-200 line-clamp-2">{{ $faq->question }}</p>
                    @if($faq->category)
                    <p class="text-xs text-slate-400 mt-1">{{ $faq->category->name }}</p>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Results --}}
        @if($faqs->isEmpty())
        <div class="text-center py-20">
            <div class="w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4" style="background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.2)">
                <svg class="w-8 h-8 text-indigo-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="text-slate-300 font-semibold mb-2">No FAQs found</h3>
            <p class="text-slate-400 text-sm">
                @if($search)
                    No results for "{{ $search }}". Try a different search term.
                @else
                    No FAQs are available in this category yet.
                @endif
            </p>
        </div>
        @else

        {{-- Accordion controls --}}
        <div class="flex items-center justify-between mb-4">
            <p class="text-sm text-slate-400">{{ $faqs->count() }} {{ Str::plural('question', $faqs->count()) }}</p>
            <div class="flex gap-3">
                <button onclick="expandAll()" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">Expand all</button>
                <span class="text-slate-700">·</span>
                <button onclick="collapseAll()" class="text-xs text-slate-400 hover:text-slate-400 transition-colors">Collapse all</button>
            </div>
        </div>

        {{-- Group by category --}}
        @php $grouped = $faqs->groupBy(fn ($faq) => $faq->category?->name ?? 'General'); @endphp

        @foreach($grouped as $categoryName => $group)
            @if($grouped->count() > 1)
            <h3 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-3 mt-8 first:mt-0">{{ $categoryName }}</h3>
            @endif

            <div class="space-y-2 mb-2" id="category-group-{{ Str::slug($categoryName) }}">
                @foreach($group as $faq)
                <div id="faq-{{ $faq->id }}"
                     class="faq-item rounded-2xl border border-white/[0.07] overflow-hidden transition-all"
                     style="background: rgba(255,255,255,0.03)">
                    <button
                        type="button"
                        class="faq-trigger w-full flex items-center justify-between gap-4 px-6 py-4 text-left"
                        aria-expanded="false"
                        onclick="toggleFaq(this)"
                    >
                        <span class="text-sm font-medium text-slate-200 leading-snug">
                            @if($search)
                                {!! \Illuminate\Support\Str::of($faq->question)->replace($search, '<mark class="bg-indigo-500/20 text-indigo-200 rounded px-0.5 not-italic">'.e($search).'</mark>') !!}
                            @else
                                {{ $faq->question }}
                            @endif
                        </span>
                        <svg class="faq-chevron w-4 h-4 flex-shrink-0 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div class="faq-answer hidden px-6 pb-5">
                        <div class="prose prose-sm prose-invert max-w-none text-slate-400">
                            {!! $faq->answer !!}
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        @endforeach

        @endif
    </div>
</div>

@push('scripts')
<script>
function toggleFaq(trigger) {
    const item = trigger.closest('.faq-item');
    const answer = item.querySelector('.faq-answer');
    const chevron = item.querySelector('.faq-chevron');
    const isOpen = !answer.classList.contains('hidden');

    if (isOpen) {
        answer.classList.add('hidden');
        chevron.style.transform = '';
        trigger.setAttribute('aria-expanded', 'false');
        sessionStorage.removeItem('faq-open-' + item.id);
    } else {
        answer.classList.remove('hidden');
        chevron.style.transform = 'rotate(180deg)';
        trigger.setAttribute('aria-expanded', 'true');
        sessionStorage.setItem('faq-open-' + item.id, '1');
    }
}

function expandAll() {
    document.querySelectorAll('.faq-item').forEach(item => {
        const trigger = item.querySelector('.faq-trigger');
        const answer = item.querySelector('.faq-answer');
        const chevron = item.querySelector('.faq-chevron');
        answer.classList.remove('hidden');
        chevron.style.transform = 'rotate(180deg)';
        trigger.setAttribute('aria-expanded', 'true');
    });
}

function collapseAll() {
    document.querySelectorAll('.faq-item').forEach(item => {
        const trigger = item.querySelector('.faq-trigger');
        const answer = item.querySelector('.faq-answer');
        const chevron = item.querySelector('.faq-chevron');
        answer.classList.add('hidden');
        chevron.style.transform = '';
        trigger.setAttribute('aria-expanded', 'false');
        sessionStorage.removeItem('faq-open-' + item.id);
    });
}

// Restore session state on page load
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.faq-item').forEach(item => {
        if (sessionStorage.getItem('faq-open-' + item.id)) {
            const trigger = item.querySelector('.faq-trigger');
            trigger && toggleFaq(trigger);
        }
    });

    // Keyboard: Enter / Space on triggers
    document.querySelectorAll('.faq-trigger').forEach(trigger => {
        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleFaq(this);
            }
        });
    });
});
</script>
@endpush
@endsection
