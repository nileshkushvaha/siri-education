<div>
    {{-- ──── STATE 1: FORM ──────────────────────────────────────── --}}
    <div @if($sent) style="display:none" @endif>
        <div class="mb-8">
            <h2 class="text-3xl font-bold text-white mb-2">Forgot password?</h2>
            <p class="text-slate-400 text-sm">Enter your email and we'll send you a reset link.</p>
        </div>

        <form wire:submit="send" class="space-y-5">
            <x-ui.auth-input label="Email address" name="email" type="email" wire:model="email" placeholder="you@example.com" autocomplete="email" required />

            <x-ui.auth-button loadingText="Sending reset link…">
                Send reset link
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </x-ui.auth-button>
        </form>

        <div class="mt-6 text-center">
            <a href="{{ route('auth.login') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-300 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to sign in
            </a>
        </div>
    </div>

    {{-- ──── STATE 2: SUCCESS ───────────────────────────────────── --}}
    @if($sent)
    <div class="text-center">
        <div class="relative inline-flex items-center justify-center mb-8">
            <div class="w-24 h-24 rounded-2xl bg-indigo-500/10 border border-indigo-500/25 flex items-center justify-center">
                <svg class="w-12 h-12 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>
            <div class="absolute -top-1 -right-1 w-6 h-6 rounded-full bg-emerald-500 flex items-center justify-center">
                <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
            </div>
        </div>

        <h2 class="text-2xl font-bold text-white mb-2">Check your inbox!</h2>
        <p class="text-slate-400 text-sm leading-relaxed mb-2">We've sent a password reset link to</p>
        <p class="text-indigo-400 font-semibold text-sm mb-6">{{ $email }}</p>

        <div class="glass rounded-xl p-4 text-left mb-6 space-y-2.5">
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Didn't get it?</p>
            @foreach(["Check your spam or junk folder", "Make sure you entered the right email", "The link will expire in 60 minutes"] as $tip)
            <div class="flex items-center gap-2.5">
                <div class="w-1.5 h-1.5 rounded-full bg-indigo-400 flex-shrink-0"></div>
                <p class="text-slate-400 text-xs">{{ $tip }}</p>
            </div>
            @endforeach
        </div>

        <button type="button" wire:click="send" wire:loading.attr="disabled"
            class="w-full px-5 py-3 rounded-xl border border-white/[0.12] text-slate-300 hover:bg-white/[0.05] hover:text-white transition font-medium text-sm mb-4 disabled:opacity-60">
            Resend reset link
        </button>

        <a href="{{ route('auth.login') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-300 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Back to sign in
        </a>
    </div>
    @endif
</div>
