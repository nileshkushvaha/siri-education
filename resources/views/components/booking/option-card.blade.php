{{--
    Selectable booking option (session type, level, subject, curriculum,
    schedule mode, time slot …). Always a real <button> with aria-pressed;
    the selected state is carried by a strong border, a tinted fill AND a
    checkmark, never by colour alone. Pass wire:click / @click via attributes.

    Props:
        selected:    bool
        title:       main label (or use the default slot)
        description: optional secondary line
        badge:       optional short tag rendered top-right
        align:       left | center   (default: left)
        size:        md | sm         (sm = compact tiles such as time slots)
--}}
@props([
    'selected' => false,
    'title' => null,
    'description' => null,
    'badge' => null,
    'align' => 'left',
    'size' => 'md',
])

@php
    $base = 'booking-option group relative flex w-full min-h-11 items-center gap-3 rounded-2xl border-2 text-left transition
        focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50 disabled:cursor-not-allowed disabled:opacity-50';
    $sizing = $size === 'sm' ? 'px-3 py-2.5' : 'p-4';
    $state = $selected
        ? 'border-indigo-500 bg-indigo-500/10 text-fg-strong shadow-sm shadow-indigo-500/10'
        : 'border-edge bg-surface-raised text-fg hover:border-indigo-300 hover:bg-indigo-500/5';
    $alignment = $align === 'center' ? 'justify-center text-center' : '';
    $classes = trim((string) preg_replace('/\s+/', ' ', "$base $sizing $state $alignment"));
@endphp

<button
    type="button"
    aria-pressed="{{ $selected ? 'true' : 'false' }}"
    {{ $attributes->merge(['class' => $classes]) }}
>
    <span class="min-w-0 flex-1 {{ $align === 'center' ? 'text-center' : '' }}">
        <span class="block {{ $size === 'sm' ? 'text-sm font-bold' : 'text-base font-bold' }} text-fg-strong">{{ $title ?? $slot }}</span>
        @if($description)
            <span class="mt-0.5 block text-sm leading-5 text-fg-muted">{{ $description }}</span>
        @endif
    </span>

    @if($badge)
        <span class="shrink-0 rounded-full bg-surface-hover px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-fg-muted">{{ $badge }}</span>
    @endif

    <span
        class="booking-option-check flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition
            {{ $selected ? 'border-indigo-500 bg-indigo-500 text-white' : 'border-edge-strong bg-transparent text-transparent' }}
            {{ $align === 'center' ? 'absolute right-2 top-2' : '' }}"
        aria-hidden="true"
    >
        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="3.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
    </span>
</button>
