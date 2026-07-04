@props([
    'src' => null,
    'name' => null,
    'alt' => null,
    'size' => 'md',
])

@php
    $sizes = [
        'xs' => 'h-6 w-6 text-xs',
        'sm' => 'h-8 w-8 text-xs',
        'md' => 'h-10 w-10 text-sm',
        'lg' => 'h-12 w-12 text-base',
        'xl' => 'h-16 w-16 text-xl',
    ];

    $initials = collect(explode(' ', trim((string) $name)))
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-100 font-semibold text-slate-700 ring-1 ring-slate-200 dark:bg-white/10 dark:text-slate-100 dark:ring-white/10 '.($sizes[$size] ?? $sizes['md'])]) }}>
    @if($src)
        <img src="{{ $src }}" alt="{{ $alt ?? $name ?? 'Avatar' }}" class="h-full w-full object-cover">
    @else
        <span aria-hidden="{{ $name ? 'false' : 'true' }}">{{ $initials ?: '?' }}</span>
    @endif
</span>
