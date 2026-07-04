@props([
    'type' => 'block',
    'lines' => 3,
])

@php
    $base = 'animate-pulse rounded-lg bg-slate-200 dark:bg-white/10';
@endphp

@if($type === 'text')
    <div {{ $attributes->merge(['class' => 'space-y-2']) }} aria-hidden="true">
        @for($i = 0; $i < $lines; $i++)
            <div class="{{ $base }} h-3 {{ $i === $lines - 1 ? 'w-2/3' : 'w-full' }}"></div>
        @endfor
    </div>
@elseif($type === 'avatar')
    <div {{ $attributes->merge(['class' => $base.' h-10 w-10 rounded-full']) }} aria-hidden="true"></div>
@else
    <div {{ $attributes->merge(['class' => $base.' h-24 w-full']) }} aria-hidden="true"></div>
@endif
