{{--
    Generic button/link primitive — <x-ui.button variant="primary">Save</x-ui.button>
    Renders <a> when href is passed, otherwise <button>. Always keyboard
    and screen-reader accessible (focus-visible ring, disabled state).

    Props:
        variant: primary | secondary | ghost | danger  (default: primary)
        size:    sm | md | lg                          (default: md)
        href:    if present, renders as a link instead of a button
        type:    button | submit | reset                (default: button; ignored when href is set)
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
])

@php
    $variants = [
        'primary' => 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/20 hover:bg-indigo-500 dark:bg-indigo-500 dark:hover:bg-indigo-400',
        'secondary' => 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-white/10 dark:bg-white/5 dark:text-slate-100 dark:hover:bg-white/10',
        'ghost' => 'bg-transparent text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10',
        'danger' => 'bg-red-600 text-white shadow-sm shadow-red-600/20 hover:bg-red-500 dark:bg-red-500 dark:hover:bg-red-400',
    ];

    $sizes = [
        'sm' => 'min-h-8 px-3 py-1.5 text-xs',
        'md' => 'min-h-10 px-5 py-2.5 text-sm',
        'lg' => 'min-h-12 px-6 py-3 text-base',
    ];

    $classes = 'inline-flex items-center justify-center gap-2 rounded-xl font-semibold transition-colors '
        .'focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 dark:focus-visible:ring-indigo-400/30 '
        .'disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50 '
        .($variants[$variant] ?? $variants['primary']).' '
        .($sizes[$size] ?? $sizes['md']);
@endphp

@if($href)
    <a
        href="{{ $disabled ? '#' : $href }}"
        @if($disabled) aria-disabled="true" tabindex="-1" @endif
        {{ $attributes->merge(['class' => $classes]) }}
    >
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif
