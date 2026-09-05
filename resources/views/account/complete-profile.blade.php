@extends('layouts.frontend')
@section('bare', true)

@section('title', 'Complete Your Profile — ' . config('app.name'))

@php
    $countryIso = $countries->keyBy('id')->map(fn ($c) => $c->iso2);
    $initialCountryId = old('country_id', $suggestedCountryId);
    $initialPhoneIso = old('phone_country_iso2', $user->profile?->phone_country_iso2 ?: ($countryIso[$initialCountryId] ?? 'US'));
@endphp

@section('content')
<div class="min-h-screen bg-surface-dark flex items-center justify-center px-4 py-16 relative overflow-hidden"
     x-data="{
        countryId: '{{ $initialCountryId }}',
        phoneIso: '{{ $initialPhoneIso }}',
        countryIso: @js($countryIso),
        placeholders: @js($phonePlaceholders),
        phoneTouched: {{ old('phone_country_iso2') ? 'true' : 'false' }},
        loading: false,
        syncPhone() { if (!this.phoneTouched && this.countryIso[this.countryId]) { this.phoneIso = this.countryIso[this.countryId]; } },
        get phonePlaceholder() { return this.placeholders[this.phoneIso] ?? 'Mobile number'; }
     }">

    <div class="absolute top-[-12rem] left-[-12rem] w-[40rem] h-[40rem] rounded-full bg-indigo-600/10 blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-[-12rem] right-[-12rem] w-[40rem] h-[40rem] rounded-full bg-violet-600/8 blur-[120px] pointer-events-none"></div>

    <div class="relative z-10 w-full max-w-lg">
        <div class="flex items-center justify-center mb-10">
            <a href="{{ route('home') }}" class="rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"><x-ui.brand-logo variant="dark" class="block h-12 w-auto" /></a>
        </div>

        <div class="auth-card p-8 shadow-2xl shadow-black/40">
            <div class="text-center mb-8">
                <div class="inline-flex w-16 h-16 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </div>
                <h2 class="text-2xl font-bold text-white mb-1">Complete your profile</h2>
                <p class="text-slate-400 text-sm">A few details before your first booking: where you are, how we can reach you, and your agreement to our terms.</p>
            </div>

            @if(session('info'))
            <div class="mb-6 rounded-xl bg-amber-500/10 border border-amber-500/25 p-4" role="status">
                <p class="text-amber-300 text-sm">{{ session('info') }}</p>
            </div>
            @endif

            <form method="POST" action="{{ route('account.complete-profile.store') }}" @submit="loading = true" class="space-y-5">
                @csrf

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="first_name" class="auth-label">First name</label>
                        <input id="first_name" name="first_name" type="text" value="{{ old('first_name', $user->first_name) }}" autocomplete="given-name" class="auth-input @error('first_name') error @enderror" required>
                        @error('first_name')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="last_name" class="auth-label">Last name</label>
                        <input id="last_name" name="last_name" type="text" value="{{ old('last_name', $user->last_name) }}" autocomplete="family-name" class="auth-input @error('last_name') error @enderror">
                        @error('last_name')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div>
                    <label for="country_id" class="auth-label">Country</label>
                    <select id="country_id" name="country_id" x-model="countryId" @change="syncPhone()" autocomplete="country" class="auth-input @error('country_id') error @enderror" required>
                        <option value="">Select your country</option>
                        @foreach($countries as $country)
                        <option value="{{ $country->id }}">{{ $country->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-xs text-slate-500">Sets your local time and lesson prices. You can change it later from your profile.</p>
                    @error('country_id')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>

                <div class="grid gap-3 sm:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                    <div>
                        <label for="phone_country_iso2" class="auth-label">Mobile country code</label>
                        <select id="phone_country_iso2" name="phone_country_iso2" x-model="phoneIso" @change="phoneTouched = true" autocomplete="tel-country-code" class="auth-input @error('phone_country_iso2') error @enderror" required>
                            @foreach($countries as $country)
                            <option value="{{ $country->iso2 }}">{{ $country->name }} {{ $country->phone_code }}</option>
                            @endforeach
                        </select>
                        @error('phone_country_iso2')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="phone" class="auth-label">Mobile number</label>
                        <input id="phone" name="phone" type="tel" value="{{ old('phone', $user->profile?->phone_national_number) }}" autocomplete="tel-national" maxlength="20" :placeholder="phonePlaceholder" class="auth-input @error('phone') error @enderror" required>
                        @error('phone')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div>
                    <x-ui.auth-checkbox name="terms" :checked="old('terms')" required>
                        I agree to the <a href="{{ url('/terms-and-conditions') }}" class="font-medium text-indigo-400 hover:text-indigo-300" target="_blank" rel="noopener">Terms and Conditions</a>
                        and <a href="{{ url('/privacy-policy') }}" class="font-medium text-indigo-400 hover:text-indigo-300" target="_blank" rel="noopener">Privacy Policy</a>
                    </x-ui.auth-checkbox>
                </div>

                <button type="submit" class="auth-btn-primary mt-2" :disabled="loading">
                    <span x-show="loading">Saving…</span>
                    <span x-show="!loading" class="flex items-center gap-2">
                        Save & continue
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </span>
                </button>
            </form>

            <div class="mt-5 text-center">
                <a href="{{ route('dashboard') }}" class="text-sm text-slate-400 hover:text-slate-300 transition">Back to dashboard</a>
            </div>
        </div>
    </div>
</div>
@endsection
