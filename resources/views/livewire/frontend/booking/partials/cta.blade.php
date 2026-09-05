{{-- The one primary action for the current stage. Expects $cta from booking-wizard.blade.php. --}}
<x-ui.button
    type="button"
    size="{{ $size ?? 'lg' }}"
    wire:click="{{ $cta['action'] }}"
    wire:loading.attr="disabled"
    wire:target="{{ $cta['action'] }}"
    :disabled="$cta['disabled']"
    class="booking-primary-cta"
>
    <span wire:loading.remove wire:target="{{ $cta['action'] }}" class="inline-flex items-center gap-2">
        {{ $cta['label'] }}
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
    </span>
    <span wire:loading wire:target="{{ $cta['action'] }}" class="inline-flex items-center gap-2">
        <x-ui.spinner size="sm" class="text-white" />
        {{ $cta['loading'] }}
    </span>
</x-ui.button>
