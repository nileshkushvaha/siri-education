@props([
    'text' => null,
    'position' => 'top',
])

@php
    $id = 'tooltip-'.Illuminate\Support\Str::uuid()->toString();
    $positions = [
        'top' => 'bottom-full left-1/2 mb-2 -translate-x-1/2',
        'bottom' => 'left-1/2 top-full mt-2 -translate-x-1/2',
        'left' => 'right-full top-1/2 mr-2 -translate-y-1/2',
        'right' => 'left-full top-1/2 ml-2 -translate-y-1/2',
    ];
@endphp

<span x-data="{ open: false }" class="relative inline-flex" x-on:mouseenter="open = true" x-on:mouseleave="open = false" x-on:focusin="open = true" x-on:focusout="open = false" aria-describedby="{{ $id }}">
    {{ $slot }}
    <span
        id="{{ $id }}"
        role="tooltip"
        x-show="open"
        x-transition.opacity
        x-cloak
        class="absolute z-50 max-w-xs whitespace-nowrap rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg dark:bg-white dark:text-slate-950 {{ $positions[$position] ?? $positions['top'] }}"
    >
        {{ $text }}
    </span>
</span>
