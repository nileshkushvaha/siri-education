<div>
    @if($status === 'verification-link-sent')
    <div class="mb-5 flex items-start gap-3 rounded-xl bg-emerald-500/10 border border-emerald-500/25 p-4 text-left" role="status">
        <svg class="w-5 h-5 text-emerald-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <p class="text-emerald-300 text-sm">A new verification link has been sent to your email address.</p>
    </div>
    @elseif($status === 'throttled')
    <div class="mb-5 flex items-start gap-3 rounded-xl bg-amber-500/10 border border-amber-500/25 p-4 text-left" role="alert">
        <svg class="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <p class="text-amber-300 text-sm">Too many attempts — please wait a minute before trying again.</p>
    </div>
    @endif

    <button type="button" wire:click="resend" wire:loading.attr="disabled" wire:target="resend"
        class="auth-btn-primary">
        <svg wire:loading wire:target="resend" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span wire:loading wire:target="resend">Sending…</span>
        <span wire:loading.remove wire:target="resend" class="flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            Resend verification email
        </span>
    </button>
</div>
