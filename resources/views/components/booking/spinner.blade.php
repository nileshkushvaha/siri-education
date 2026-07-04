{{-- Loading indicator. Prop: label (announced to screen readers). --}}
@props(['label' => 'Loading…'])

<div {{ $attributes->merge(['class' => 'flex items-center justify-center gap-2 py-10 text-slate-500']) }}
     role="status" aria-live="polite">
    <svg class="h-5 w-5 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24" aria-hidden="true">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
    </svg>
    <span class="text-sm">{{ $label }}</span>
</div>
