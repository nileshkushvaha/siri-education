@props([
    'id' => 'drawer-'.Illuminate\Support\Str::uuid()->toString(),
    'title' => null,
    'open' => false,
    'side' => 'right',
    'size' => 'md',
])

@php
    $sizes = [
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-xl',
    ];
    $sideClass = $side === 'left' ? 'left-0' : 'right-0';
    $enterFrom = $side === 'left' ? '-translate-x-full' : 'translate-x-full';
    $titleId = $id.'-title';
@endphp

<div
    x-data="{ open: @js($open) }"
    x-on:open-drawer.window="if ($event.detail === '{{ $id }}' || $event.detail?.id === '{{ $id }}') open = true"
    x-on:close-drawer.window="if ($event.detail === '{{ $id }}' || $event.detail?.id === '{{ $id }}') open = false"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $titleId }}"
>
    <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" x-show="open" x-on:click="open = false" x-transition.opacity aria-hidden="true"></div>

    <aside
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="{{ $enterFrom }}"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="{{ $enterFrom }}"
        {{ $attributes->merge(['class' => 'absolute top-0 '.$sideClass.' h-full w-full '.($sizes[$size] ?? $sizes['md']).' overflow-y-auto border-slate-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-slate-950 '.($side === 'left' ? 'border-r' : 'border-l')]) }}
    >
        <div class="flex items-start justify-between gap-4">
            @if($title)
                <h2 id="{{ $titleId }}" class="text-lg font-semibold text-slate-900 dark:text-white">{{ $title }}</h2>
            @else
                <h2 id="{{ $titleId }}" class="sr-only">Drawer</h2>
            @endif
            <button type="button" x-on:click="open = false" class="rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:hover:bg-white/10 dark:hover:text-slate-200 dark:focus-visible:ring-indigo-400/20">
                <span class="sr-only">Close drawer</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="mt-4">{{ $slot }}</div>
    </aside>
</div>
