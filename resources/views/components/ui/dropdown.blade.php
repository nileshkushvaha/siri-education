@props([
    'align' => 'right',
    'width' => 'w-56',
])

@php
    $alignClass = $align === 'left' ? 'left-0' : 'right-0';
@endphp

<div x-data="{ open: false }" x-on:keydown.escape.window="open = false" class="relative inline-block text-left" {{ $attributes }}>
    <div x-on:click="open = ! open">
        @isset($trigger)
            {{ $trigger }}
        @else
            <x-ui.button type="button" variant="secondary" size="sm">Open</x-ui.button>
        @endisset
    </div>

    <div
        x-show="open"
        x-transition
        x-on:click.outside="open = false"
        x-cloak
        class="absolute {{ $alignClass }} z-40 mt-2 {{ $width }} origin-top-right rounded-xl border border-slate-200 bg-white p-1 shadow-lg ring-1 ring-black/5 dark:border-white/10 dark:bg-slate-950"
        role="menu"
    >
        {{ $slot }}
    </div>
</div>
