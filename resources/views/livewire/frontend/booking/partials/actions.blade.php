<div class="mt-8 flex items-center justify-between gap-3 border-t border-edge pt-5">
    <div>
        @if($currentStage !== 'learning')
            <button
                type="button"
                wire:click="backStage"
                class="inline-flex min-h-11 items-center gap-2 rounded-xl px-3 text-sm font-semibold text-fg-muted transition hover:bg-surface-hover hover:text-fg-strong focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
                Back
            </button>
        @endif
    </div>

    <div class="hidden items-center gap-4 lg:flex">
        @if($cta['disabled'])
            <span class="text-xs font-medium text-fg-muted">{{ $cta['hint'] }}</span>
        @endif
        @include('livewire.frontend.booking.partials.cta')
    </div>
</div>
