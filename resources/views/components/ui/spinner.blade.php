@props([
    'size' => 'md',
    'label' => 'Loading',
])

@php
    $sizes = [
        'sm' => 'h-4 w-4 border-2',
        'md' => 'h-6 w-6 border-2',
        'lg' => 'h-8 w-8 border-[3px]',
    ];
@endphp

<span role="status" {{ $attributes->merge(['class' => 'inline-flex items-center justify-center']) }}>
    <span class="animate-spin rounded-full border-current border-r-transparent text-indigo-600 dark:text-indigo-300 {{ $sizes[$size] ?? $sizes['md'] }}" aria-hidden="true"></span>
    <span class="sr-only">{{ $label }}</span>
</span>
