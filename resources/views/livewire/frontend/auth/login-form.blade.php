@php
    $authSettings = app(\App\Settings\AuthenticationSettings::class);
@endphp

<div x-data="{ showPass: false }">

    {{-- Account locked / blocked / inactive notice --}}
    @if($bannerType === 'locked')
    <div class="mb-5 flex items-start gap-3 rounded-xl bg-amber-500/10 border border-amber-500/25 p-4" role="alert">
        <svg class="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        <p class="text-amber-300 text-sm">{{ $bannerMessage }}</p>
    </div>
    @endif

    {{-- Unverified email notice --}}
    @if($bannerType === 'unverified')
    <div class="mb-5 rounded-xl bg-blue-500/10 border border-blue-500/25 p-4" role="alert">
        <div class="flex items-start gap-3 mb-3">
            <svg class="w-5 h-5 text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <div>
                <p class="text-blue-300 text-sm font-semibold">Email not verified</p>
                <p class="text-blue-300/80 text-xs mt-0.5">{{ $bannerMessage }}</p>
            </div>
        </div>
        <button type="button" wire:click="resendVerification" wire:loading.attr="disabled"
            class="w-full text-center text-xs font-medium text-blue-400 hover:text-white bg-blue-500/15 hover:bg-blue-500/30 border border-blue-500/30 rounded-lg py-2 px-3 transition disabled:opacity-60">
            ✉ Resend verification email
        </button>
    </div>
    @endif

    @if(! $bannerType && $bannerMessage)
    <div class="mb-5 flex items-start gap-3 rounded-xl bg-emerald-500/10 border border-emerald-500/25 p-4" role="status">
        <svg class="w-5 h-5 text-emerald-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <p class="text-emerald-300 text-sm">{{ $bannerMessage }}</p>
    </div>
    @endif

    <form wire:submit="login" class="space-y-5">
        <x-ui.auth-input
            label="Email address"
            name="email"
            type="email"
            wire:model="email"
            placeholder="you@example.com"
            autocomplete="email"
            required
        />

        <div>
            <div class="flex items-center justify-between mb-1.5">
                <label for="password" class="auth-label mb-0">Password</label>
                <a href="{{ route('auth.password.request') }}" class="text-xs text-indigo-400 hover:text-indigo-300 transition font-medium">Forgot password?</a>
            </div>
            <div class="relative">
                <input
                    :type="showPass ? 'text' : 'password'"
                    id="password"
                    wire:model="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    class="auth-input pr-11 @error('password') error @enderror"
                    required
                >
                <button type="button" @click="showPass = !showPass" aria-label="Toggle password visibility"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-300 transition focus:outline-none">
                    <svg x-show="!showPass" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <svg x-show="showPass" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                </button>
            </div>
            @error('password')
                <p class="mt-1.5 text-xs text-red-400" role="alert">{{ $message }}</p>
            @enderror
        </div>

        @if($authSettings->remember_me_enabled)
            <x-ui.auth-checkbox label="Keep me signed in for 30 days" name="remember" wire:model="remember" />
        @endif

        <x-ui.auth-button loadingText="Signing in…">
            Sign in
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
        </x-ui.auth-button>
    </form>
</div>
