<div x-data="{
    showPass: false,
    showConfirm: false,
    password: '',
    confirmVal: '',
    strength: 0,
    strengthLabel: '',
    passwordsMatch: false,
    checkStrength(val) {
        this.password = val;
        let s = 0;
        if (val.length >= 8) s++;
        if (val.length >= 8) s++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) s++;
        if (/\d/.test(val)) s++;
        if (/[^A-Za-z0-9]/.test(val)) s++;
        this.strength = s;
        this.strengthLabel = ['','Very weak','Weak','Fair','Strong','Very strong'][s] || '';
        if (this.confirmVal) this.checkMatch(this.confirmVal);
    },
    checkMatch(val) {
        this.confirmVal = val;
        this.passwordsMatch = val === this.password;
    },
}">
    @if($errors->any())
    <div class="mb-6 flex items-start gap-3 rounded-xl bg-red-500/10 border border-red-500/25 p-4" role="alert">
        <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <div class="space-y-1">
            @foreach($errors->all() as $error)
            <p class="text-red-300 text-sm">{{ $error }}</p>
            @endforeach
        </div>
    </div>
    @endif

    <form wire:submit="resetPassword" class="space-y-5">
        <div>
            <label for="password" class="auth-label">New password</label>
            <div class="relative">
                <input :type="showPass ? 'text' : 'password'" id="password" wire:model="password"
                    placeholder="Min. 8 characters" autocomplete="new-password"
                    @input="checkStrength($event.target.value)"
                    class="auth-input pr-11" required>
                <button type="button" @click="showPass = !showPass" aria-label="Toggle password visibility"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-300 transition focus:outline-none">
                    <svg x-show="!showPass" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <svg x-show="showPass" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                </button>
            </div>

            <div x-show="password.length > 0" class="mt-2.5">
                <div class="flex gap-1.5 mb-1.5">
                    <template x-for="i in 5">
                        <div class="flex-1 h-1.5 rounded-full transition-all duration-300"
                            :class="i <= strength ? (strength <= 1 ? 'bg-red-500' : strength <= 2 ? 'bg-amber-500' : strength <= 3 ? 'bg-yellow-400' : 'bg-emerald-400') : 'bg-white/10'"></div>
                    </template>
                </div>
                <p class="text-xs transition-colors" :class="strength <= 1 ? 'text-red-400' : strength <= 2 ? 'text-amber-400' : strength <= 3 ? 'text-yellow-400' : 'text-emerald-400'" x-text="strengthLabel"></p>
            </div>
        </div>

        <div>
            <label for="password_confirmation" class="auth-label">Confirm new password</label>
            <div class="relative">
                <input :type="showConfirm ? 'text' : 'password'" id="password_confirmation" wire:model="password_confirmation"
                    placeholder="Repeat your new password" autocomplete="new-password"
                    @input="checkMatch($event.target.value)"
                    class="auth-input pr-16">
                <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-1.5">
                    <div x-show="confirmVal.length > 0">
                        <svg x-show="passwordsMatch" class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        <svg x-show="!passwordsMatch" class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                    </div>
                    <button type="button" @click="showConfirm = !showConfirm" aria-label="Toggle password visibility" class="text-slate-400 hover:text-slate-300 transition focus:outline-none">
                        <svg x-show="!showConfirm" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <svg x-show="showConfirm" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                    </button>
                </div>
            </div>
            <p x-show="confirmVal.length > 0 && !passwordsMatch" class="mt-1 text-xs text-red-400">Passwords don't match</p>
        </div>

        <x-ui.auth-button loadingText="Setting new password…">
            Set new password &amp; sign in
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
        </x-ui.auth-button>
    </form>
</div>
