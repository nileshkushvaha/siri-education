@props([
    'id' => 'modal-'.Illuminate\Support\Str::uuid()->toString(),
    'title' => null,
    'open' => false,
    'size' => 'md',
])

@php
    $sizes = [
        'sm' => 'max-w-md',
        'md' => 'max-w-lg',
        'lg' => 'max-w-2xl',
        'xl' => 'max-w-4xl',
    ];
    $titleId = $id.'-title';
@endphp

<div
    x-data="{ open: @js($open) }"
    x-on:open-modal.window="if ($event.detail === '{{ $id }}' || $event.detail?.id === '{{ $id }}') open = true"
    x-on:close-modal.window="if ($event.detail === '{{ $id }}' || $event.detail?.id === '{{ $id }}') open = false"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:py-10"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $titleId }}"
>
    <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm" x-show="open" x-on:click="open = false" x-transition.opacity aria-hidden="true"></div>

    <div class="relative mx-auto flex min-h-full items-center justify-center">
        <div
            x-show="open"
            x-transition
            {{ $attributes->merge(['class' => 'relative w-full rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-slate-950 '.($sizes[$size] ?? $sizes['md'])]) }}
        >
            <div class="flex items-start justify-between gap-4">
                @if($title)
                    <h2 id="{{ $titleId }}" class="text-lg font-semibold text-slate-900 dark:text-white">{{ $title }}</h2>
                @else
                    <h2 id="{{ $titleId }}" class="sr-only">Dialog</h2>
                @endif
                <button type="button" x-on:click="open = false" class="rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:hover:bg-white/10 dark:hover:text-slate-200 dark:focus-visible:ring-indigo-400/20">
                    <span class="sr-only">Close dialog</span>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="mt-4">{{ $slot }}</div>
        </div>
    </div>
</div>
