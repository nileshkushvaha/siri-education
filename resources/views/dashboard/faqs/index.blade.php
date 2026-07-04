@extends('layouts.account')

@section('title', 'FAQs — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'FAQs'],
    ]" />
@endsection

@section('account-content')

<div class="mb-6">
    <h1 class="text-xl font-bold text-white">FAQs</h1>
    <p class="text-slate-400 text-sm mt-1">Find answers to common questions.</p>
</div>

{{-- Search --}}
<form action="{{ route('dashboard.faqs') }}" method="GET" class="relative mb-6" role="search">
    <input
        type="search"
        name="q"
        value="{{ $search }}"
        placeholder="Search FAQs..."
        autocomplete="off"
        class="w-full py-3 pl-10 pr-4 rounded-xl border border-white/[0.07] text-white text-sm placeholder-slate-500 focus:outline-none focus:border-indigo-500/40 focus:ring-1 focus:ring-indigo-500/20 transition-all"
        style="background: rgba(255,255,255,0.03)"
    >
    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
    </svg>
    @if($search)
        <a href="{{ route('dashboard.faqs') }}"
           class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-300 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </a>
    @endif
</form>

{{-- FAQ Accordion --}}
@if($faqs->isEmpty())
<div class="text-center py-16">
    <div class="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-3"
         style="background: rgba(99,102,241,0.08); border: 1px solid rgba(99,102,241,0.15)">
        <svg class="w-7 h-7 text-indigo-400/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
    </div>
    <p class="text-slate-400 text-sm font-medium">No FAQs found</p>
    <p class="text-slate-400 text-xs mt-1">
        @if($search)
            No results for "{{ $search }}".
        @else
            Nothing here yet.
        @endif
    </p>
</div>
@else

<div class="flex items-center justify-between mb-3">
    <span class="text-xs text-slate-400">{{ $faqs->count() }} {{ Str::plural('result', $faqs->count()) }}</span>
    <div class="flex gap-3">
        <button onclick="expandAll()" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">Expand all</button>
        <span class="text-slate-700">·</span>
        <button onclick="collapseAll()" class="text-xs text-slate-400 hover:text-slate-400 transition-colors">Collapse all</button>
    </div>
</div>

<div class="space-y-1.5">
    @foreach($faqs as $faq)
    <div id="faq-{{ $faq->id }}"
         class="faq-item rounded-xl border border-white/[0.06] overflow-hidden"
         style="background: rgba(255,255,255,0.02)">
        <button
            type="button"
            class="faq-trigger w-full flex items-center justify-between gap-3 px-4 py-3.5 text-left"
            aria-expanded="false"
            onclick="toggleFaq(this)"
        >
            <span class="text-sm text-slate-200 leading-snug">
                @if($search)
                    {!! \Illuminate\Support\Str::of($faq->question)->replace($search, '<mark class="bg-indigo-500/20 text-indigo-200 rounded px-0.5">'.e($search).'</mark>') !!}
                @else
                    {{ $faq->question }}
                @endif
            </span>
            <svg class="faq-chevron w-4 h-4 flex-shrink-0 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>
        <div class="faq-answer hidden px-4 pb-4">
            <div class="prose prose-sm prose-invert max-w-none text-slate-400 border-t border-white/[0.05] pt-3">
                {!! $faq->answer !!}
            </div>
        </div>
    </div>
    @endforeach
</div>

@endif

@push('scripts')
<script>
function toggleFaq(trigger) {
    const item = trigger.closest('.faq-item');
    const answer = item.querySelector('.faq-answer');
    const chevron = item.querySelector('.faq-chevron');
    const isOpen = !answer.classList.contains('hidden');
    answer.classList.toggle('hidden', isOpen);
    chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
    trigger.setAttribute('aria-expanded', String(!isOpen));
    isOpen
        ? sessionStorage.removeItem('faq-open-' + item.id)
        : sessionStorage.setItem('faq-open-' + item.id, '1');
}
function expandAll() {
    document.querySelectorAll('.faq-item').forEach(item => {
        item.querySelector('.faq-answer').classList.remove('hidden');
        item.querySelector('.faq-chevron').style.transform = 'rotate(180deg)';
        item.querySelector('.faq-trigger').setAttribute('aria-expanded', 'true');
    });
}
function collapseAll() {
    document.querySelectorAll('.faq-item').forEach(item => {
        item.querySelector('.faq-answer').classList.add('hidden');
        item.querySelector('.faq-chevron').style.transform = '';
        item.querySelector('.faq-trigger').setAttribute('aria-expanded', 'false');
    });
}
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.faq-item').forEach(item => {
        if (sessionStorage.getItem('faq-open-' + item.id)) {
            const t = item.querySelector('.faq-trigger');
            t && toggleFaq(t);
        }
    });
});
</script>
@endpush

@endsection
