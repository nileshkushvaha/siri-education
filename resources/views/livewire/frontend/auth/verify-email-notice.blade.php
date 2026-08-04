<div>
    @if($status === 'code-sent')
    <div class="mb-5 flex items-start gap-3 rounded-xl bg-emerald-500/10 border border-emerald-500/25 p-4 text-left" role="status">
        <svg class="w-5 h-5 text-emerald-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <p class="text-emerald-300 text-sm">A new code has been sent to your email address.</p>
    </div>
    @elseif($status === 'throttled')
    <div class="mb-5 flex items-start gap-3 rounded-xl bg-amber-500/10 border border-amber-500/25 p-4 text-left" role="alert">
        <svg class="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <p class="text-amber-300 text-sm">Too many requests — please wait a minute before asking for another code.</p>
    </div>
    @endif

    <form wire:submit="verify" class="text-left">
        <label for="verification-code" class="block text-sm font-semibold text-slate-300 mb-2">Enter the 6-digit code</label>

        <input
            id="verification-code"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="6"
            wire:model="code"
            placeholder="000000"
            aria-describedby="verification-code-help"
            class="auth-input w-full py-3 text-center text-2xl font-bold tracking-[0.6em]"
        >

        @error('code')
        <p class="mt-2 text-sm text-rose-400" role="alert">{{ $message }}</p>
        @enderror

        <p id="verification-code-help" class="mt-2 text-xs text-slate-500">
            The code expires in {{ \App\Services\Auth\EmailVerificationOtpService::CODE_TTL_MINUTES }} minutes.
        </p>

        <button type="submit" wire:loading.attr="disabled" wire:target="verify" class="auth-btn-primary mt-5 w-full">
            <svg wire:loading wire:target="verify" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span wire:loading wire:target="verify">Verifying…</span>
            <span wire:loading.remove wire:target="verify">Verify and continue</span>
        </button>
    </form>

    <button type="button" wire:click="resend" wire:loading.attr="disabled" wire:target="resend"
        class="mt-4 w-full text-sm font-semibold text-indigo-300 transition hover:text-indigo-200 disabled:opacity-50">
        <span wire:loading wire:target="resend">Sending…</span>
        <span wire:loading.remove wire:target="resend">Send me a new code</span>
    </button>
</div>
