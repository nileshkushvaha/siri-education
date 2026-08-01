<div x-data="{ showPass: false, showConfirm: false }">
    @if($banner)
    <div class="mb-5 rounded-xl border border-red-500/25 bg-red-500/10 p-4 text-sm text-red-300" role="alert">{{ $banner }}</div>
    @endif

    <form wire:submit="register" class="space-y-4" novalidate>
        <fieldset class="space-y-3">
            <legend class="mb-2 text-sm font-semibold text-white">Your account details</legend>
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.auth-input label="First name" name="first_name" wire:model="first_name" placeholder="Aarav" autocomplete="given-name" required />
                <x-ui.auth-input label="Last name (optional)" name="last_name" wire:model="last_name" placeholder="Sharma" autocomplete="family-name" />
            </div>
            <x-ui.auth-input label="Email address" name="email" type="email" wire:model="email" placeholder="you@sirieducation.com" autocomplete="email" required />

            <div>
                <label for="country_id" class="auth-label">Country of residence</label>
                <select id="country_id" wire:model.live="country_id" autocomplete="country" class="auth-input @error('country_id') error @enderror" required>
                    <option value="">Select your country</option>
                    @foreach($countries as $country)
                    <option value="{{ $country['id'] }}">{{ $country['label'] }} — {{ $country['currency'] }}</option>
                    @endforeach
                </select>
                <p class="mt-1.5 text-xs text-slate-400">Your billing currency, pricing, and payment options are set from this country.</p>
                @error('country_id')<p class="mt-1 text-xs text-red-400" role="alert">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="phone" class="auth-label">Mobile number (optional)</label>
                <div class="grid gap-3 sm:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                    <select id="phone_country_iso2" wire:model.live="phone_country_iso2" class="auth-input" aria-label="Phone country">
                        @foreach($countries as $country)
                        <option value="{{ $country['iso2'] }}">{{ $country['label'] }} {{ $country['dial_code'] }}</option>
                        @endforeach
                    </select>
                    <input id="phone" name="phone" type="tel" wire:model="phone" class="auth-input @error('phone') error @enderror" placeholder="(202) 555-0123" autocomplete="tel-national" maxlength="20">
                </div>
                @if($phone_country_was_manually_changed)
                @php($residenceIso2 = collect($countries)->firstWhere('id', $country_id)['iso2'] ?? null)
                @if($residenceIso2 && $residenceIso2 !== $phone_country_iso2)
                <p class="mt-2 text-xs text-amber-300" role="status">Your mobile number uses a different country code from your country of residence. You can continue if this is correct.</p>
                <button type="button" wire:click="useResidenceCountry" class="mt-2 min-h-11 text-xs font-semibold text-indigo-300 underline">Use residence country</button>
                @endif
                @endif
                <p class="mt-1.5 text-xs text-slate-400">We’ll verify this number before enabling account-sensitive actions.</p>
                @error('phone')<p class="mt-1 text-xs text-red-400" role="alert">{{ $message }}</p>@enderror
            </div>
            <x-ui.auth-input label="Referral code (optional)" name="referral_code" wire:model="referral_code" placeholder="e.g. ABCD2345" autocomplete="off" />
        </fieldset>

        <fieldset class="border-t border-white/[0.08] pt-4">
            <legend class="mb-2 text-sm font-semibold text-white">Secure your account</legend>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="password" class="auth-label">Password</label>
                    <div class="relative">
                        <input :type="showPass ? 'text' : 'password'" id="password" wire:model="password" placeholder="Create a strong password" autocomplete="new-password" class="auth-input pr-12 @error('password') error @enderror" required>
                        <button type="button" @click="showPass = !showPass" class="absolute inset-y-0 right-0 inline-flex min-h-11 w-11 items-center justify-center rounded-r-xl text-slate-400 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400" aria-label="Toggle password visibility">●</button>
                    </div>
                    @error('password')<p class="mt-1 text-xs text-red-400" role="alert">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="password_confirmation" class="auth-label">Confirm password</label>
                    <div class="relative">
                        <input :type="showConfirm ? 'text' : 'password'" id="password_confirmation" wire:model="password_confirmation" placeholder="Repeat your password" autocomplete="new-password" class="auth-input pr-12" required>
                        <button type="button" @click="showConfirm = !showConfirm" class="absolute inset-y-0 right-0 inline-flex min-h-11 w-11 items-center justify-center rounded-r-xl text-slate-400 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400" aria-label="Toggle confirmation visibility">●</button>
                    </div>
                    @error('password_confirmation')<p class="mt-1 text-xs text-red-400" role="alert">{{ $message }}</p>@enderror
                </div>
            </div>
            <p class="mt-2 w-full text-xs leading-relaxed text-slate-400">{{ $this->passwordPolicyHint() }}</p>
        </fieldset>

        <fieldset class="rounded-2xl border border-indigo-400/20 bg-indigo-500/[0.06] p-3.5">
            <legend class="px-1 text-sm font-semibold text-white">Quick security check</legend>
            <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label for="captcha_answer" class="auth-label">What is {{ $captchaQuestion }}?</label>
                    <input id="captcha_answer" wire:model="captcha_answer" inputmode="numeric" autocomplete="off" class="auth-input @error('captcha_answer') error @enderror" placeholder="Your answer" required>
                </div>
                <button type="button" wire:click="refreshCaptcha" wire:loading.attr="disabled" wire:target="refreshCaptcha" class="inline-flex min-h-11 min-w-36 items-center justify-center rounded-xl border border-white/10 px-4 text-sm font-semibold text-slate-300 hover:bg-white/[0.06] disabled:cursor-wait disabled:opacity-70 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">
                    <span wire:loading.remove wire:target="refreshCaptcha">New question</span>
                    <span wire:loading wire:target="refreshCaptcha" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Refreshing…
                    </span>
                </button>
            </div>
            @error('captcha_answer')<p class="mt-2 text-xs text-red-400" role="alert">{{ $message }}</p>@enderror
        </fieldset>

        <x-ui.auth-checkbox name="terms" wire:model="terms" required>
            I agree to the <a href="{{ url('/terms-of-service') }}" class="font-medium text-indigo-400 hover:text-indigo-300">Terms of Service</a>
            and <a href="{{ url('/privacy-policy') }}" class="font-medium text-indigo-400 hover:text-indigo-300">Privacy Policy</a>.
        </x-ui.auth-checkbox>

        <x-ui.auth-button loadingText="Creating your account…" loadingTarget="register">Create account <span aria-hidden="true">→</span></x-ui.auth-button>

        <p class="text-center text-sm text-slate-400">Already registered? <a href="{{ route('auth.login') }}" class="font-semibold text-indigo-400 hover:text-indigo-300">Sign in</a></p>
    </form>
</div>