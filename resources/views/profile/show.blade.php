@extends('layouts.account')

@section('title', 'My Profile — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'My Profile'],
    ]" />
@endsection

@section('account-content')
<div x-data="{
    activeTab: '{{ request('tab', session('active_tab', 'general')) }}',
    avatarPreview: '{{ $accountProfileSummary->avatarUrl }}',
    uploading: false,
    uploadError: '',
    coverPreview: '{{ $accountProfileSummary->coverUrl }}',
    coverUploading: false,
    coverUploadError: '',
    selectedCountryId: '{{ old('country_id', $user->profile->country_id) }}',
    selectedPhoneCountry: '{{ old('phone_country_iso2', $user->profile->phone_country_iso2 ?: $user->profile->country?->iso2 ?: 'US') }}',
    phonePlaceholders: @js($phonePlaceholders),
    states: @js($states->map(fn ($state) => ['id' => $state->id, 'country_id' => $state->country_id, 'name' => $state->name])),

    async uploadAvatar(event) {
        const file = event.target.files[0];
        if (!file) return;
        this.uploading = true;
        this.uploadError = '';
        const form = new FormData();
        form.append('avatar', file);
        form.append('_token', document.querySelector('meta[name=csrf-token]').content);
        try {
            const res = await fetch('{{ route('profile.avatar.upload') }}', { method: 'POST', body: form });
            const json = await res.json();
            if (json.success) { this.avatarPreview = json.url; }
            else { this.uploadError = 'Upload failed. Please try again.'; }
        } catch (e) { this.uploadError = 'Upload error. Please try again.'; }
        finally { this.uploading = false; }
    },

    async deleteAvatar() {
        if (!confirm('Remove your profile photo?')) return;
        const res = await fetch('{{ route('profile.avatar.delete') }}', {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
        });
        const json = await res.json();
        if (json.success) this.avatarPreview = '';
    },

    async uploadCover(event) {
        const file = event.target.files[0];
        if (!file) return;
        this.coverUploading = true;
        this.coverUploadError = '';
        const form = new FormData();
        form.append('cover', file);
        form.append('_token', document.querySelector('meta[name=csrf-token]').content);
        try {
            const res = await fetch('{{ route('profile.cover.upload') }}', { method: 'POST', body: form });
            const json = await res.json();
            if (json.success) { this.coverPreview = json.url; }
            else { this.coverUploadError = 'Upload failed. Please try again.'; }
        } catch (e) { this.coverUploadError = 'Upload error. Please try again.'; }
        finally { this.coverUploading = false; }
    },

    async deleteCover() {
        if (!confirm('Remove your cover photo?')) return;
        const res = await fetch('{{ route('profile.cover.delete') }}', {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
        });
        const json = await res.json();
        if (json.success) this.coverPreview = '';
    },

    get statesForSelectedCountry() {
        if (!this.selectedCountryId) return [];
        return this.states.filter(s => String(s.country_id) === String(this.selectedCountryId));
    },

    get phonePlaceholder() {
        return this.phonePlaceholders[this.selectedPhoneCountry] || 'Enter mobile number';
    }
}">

    {{-- ── PROFILE HERO BANNER (cover + avatar) ────────────────────────── --}}
    <x-account.profile-header :summary="$accountProfileSummary" variant="full">
        <x-slot:avatar>
            <template x-if="avatarPreview">
                <img :src="avatarPreview" class="w-full h-full object-cover" alt="Avatar">
            </template>
            <template x-if="!avatarPreview">
                <span class="text-3xl font-bold text-white">{{ $accountProfileSummary->initial }}</span>
            </template>
        </x-slot:avatar>
        <x-slot:avatarActions>
            {{-- Camera overlay --}}
            <label class="absolute inset-0 rounded-2xl flex items-center justify-center bg-black/50 opacity-0 group-hover:opacity-100 cursor-pointer transition-opacity"
                   :class="{'cursor-not-allowed': uploading}">
                <input type="file" class="sr-only" accept="image/*" @change="uploadAvatar($event)" :disabled="uploading">
                <svg x-show="!uploading" class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <svg x-show="uploading" class="w-6 h-6 text-white animate-spin" fill="none" viewBox="0 0 24 24" style="display:none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
            </label>
        </x-slot:avatarActions>
        <x-slot:coverActions>
            <label class="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 group-hover/cover:opacity-100 cursor-pointer transition-opacity"
                   :class="{'cursor-not-allowed': coverUploading}">
                <input type="file" class="sr-only" accept="image/*" @change="uploadCover($event)" :disabled="coverUploading">
                <span class="flex items-center gap-2 px-4 py-2 rounded-xl bg-black/50 text-white text-xs font-semibold">
                    <svg x-show="!coverUploading" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <svg x-show="coverUploading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" style="display:none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <span x-text="coverPreview ? 'Change cover' : 'Add cover'"></span>
                </span>
            </label>
            <button x-show="coverPreview" @click="deleteCover()" type="button"
                class="absolute top-3 right-3 px-3 py-1.5 rounded-lg bg-black/50 text-white text-xs font-medium hover:bg-red-500/60 transition-colors"
                style="display:none">
                Remove
            </button>
        </x-slot:coverActions>

        @if($user->profile->country)
            <p class="text-slate-400 text-xs mt-1">{{ $user->profile->country->flag }} {{ $user->profile->country->name }}</p>
        @endif
        <p x-show="uploadError" x-text="uploadError" class="text-xs text-red-400 mt-2"></p>
        <p x-show="coverUploadError" x-text="coverUploadError" class="text-xs text-red-400 mt-2"></p>
        <button x-show="avatarPreview" @click="deleteAvatar()" type="button"
            class="mt-2 flex items-center gap-1 text-xs text-slate-400 hover:text-red-400 transition-colors"
            style="display:none">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            Remove photo
        </button>

        <x-slot:actions>
            <div class="text-xs text-slate-400 text-right">Hover photos to change</div>
        </x-slot:actions>
    </x-account.profile-header>

    <div class="mb-6">
        <x-account.profile-completion :percentage="$accountProfileSummary->profileCompletion" :breakdown="$completionBreakdown" />
    </div>

    <section class="mb-6 overflow-hidden rounded-2xl border border-indigo-400/15 bg-gradient-to-r from-indigo-500/[0.10] via-violet-500/[0.05] to-transparent p-5 sm:p-6" aria-labelledby="profile-workspace-title">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-indigo-300">{{ $portalAudience === \App\Enums\PortalAudience::Instructor ? 'Instructor profile' : 'Student profile' }}</p>
                <h1 id="profile-workspace-title" class="mt-1 text-xl font-bold text-white">{{ $portalAudience === \App\Enums\PortalAudience::Instructor ? 'Build trust with a complete teaching profile' : 'Personalize your learning experience' }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">{{ $portalAudience === \App\Enums\PortalAudience::Instructor ? 'Keep your professional story accurate here, then manage teaching credentials and preferences in instructor setup.' : 'Your academic level, preferred subjects, language, and timezone help us tailor lessons and recommendations.' }}</p>
            </div>
            @if($portalAudience === \App\Enums\PortalAudience::Instructor)
                <a href="{{ route('dashboard.instructor.onboarding') }}" class="inline-flex min-h-11 flex-shrink-0 items-center justify-center rounded-xl bg-indigo-500 px-4 text-sm font-semibold text-white hover:bg-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-300">Teaching profile setup</a>
            @else
                <a href="{{ route('dashboard.learning-goals') }}" class="inline-flex min-h-11 flex-shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/[0.05] px-4 text-sm font-semibold text-white hover:bg-white/[0.09] focus:outline-none focus:ring-2 focus:ring-indigo-300">Manage learning goals</a>
            @endif
        </div>
    </section>

    {{-- ── MAIN CONTENT ──────────────────────────────────────────────── --}}
    <div>

        {{-- ── Horizontal Tab Bar ───────────────────────────────────────── --}}
        {{-- The account sidebar (Dashboard / My Profile) already lives in the
             layout — this is page-local tab navigation only, so it renders as
             a horizontal bar rather than a second vertical sidebar. --}}
        <div class="flex items-center gap-1 overflow-x-auto rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-1.5 mb-6">
            @foreach([
                ['general',      'Profile',       'heroicon-user',    'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',             'indigo'],
                ['security',     'Security',      'heroicon-shield',  'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z', 'emerald'],
                ['notifications','Notifications', 'heroicon-bell',    'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9', 'sky'],
            ] as [$tab, $label, $_, $icon, $color])
            <button type="button" @click="activeTab = '{{ $tab }}'"
                :class="activeTab === '{{ $tab }}'
                    ? 'bg-{{ $color }}-600/15 text-{{ $color }}-300'
                    : 'text-slate-400 hover:text-white hover:bg-white/[0.04]'"
                class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium transition-all whitespace-nowrap">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
                </svg>
                {{ $label }}
            </button>
            @endforeach
        </div>

        {{-- ── Tab Content ──────────────────────────────────────────────── --}}
        <div class="space-y-5">

                {{-- ══════════════════════════════════════════════════════ --}}
                {{-- TAB: GENERAL                                           --}}
                {{-- ══════════════════════════════════════════════════════ --}}
                <div x-show="activeTab === 'general'"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0">

                    <form method="POST" action="{{ route('profile.update') }}">
                        @csrf

                        {{-- Personal Info --}}
                        <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-5 sm:p-7 mb-5">
                            <div class="flex items-center gap-3 mb-6 pb-5 border-b border-white/[0.04]">
                                <div class="w-9 h-9 rounded-xl bg-indigo-500/15 border border-indigo-500/25 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4.5 h-4.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="text-base font-semibold text-white">Personal Information</h2>
                                    <p class="text-xs text-slate-400">Your name, contact, and identity details</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                                {{-- First Name --}}
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">First Name <span class="text-red-400">*</span></label>
                                    <input type="text" name="first_name" value="{{ old('first_name', $user->first_name) }}" autocomplete="given-name"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border @error('first_name') border-red-500/50 @else border-white/[0.05] @enderror text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all"
                                        placeholder="Enter your first name" required>
                                    @error('first_name')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                                </div>

                                {{-- Last Name --}}
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Last Name</label>
                                    <input type="text" name="last_name" value="{{ old('last_name', $user->last_name) }}" autocomplete="family-name"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all"
                                        placeholder="Enter your last name">
                                </div>

                                {{-- Mobile phone numbering plan is independent of residence/billing country. --}}
                                <div class="sm:col-span-2 lg:col-span-3">
                                    <h3 class="text-sm font-semibold text-white mb-3">Contact and regional settings</h3>
                                    <div class="grid gap-3 sm:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                                        <div>
                                            <label for="phone_country_iso2" class="block text-xs font-semibold text-slate-400 mb-2">Phone country</label>
                                            <select id="phone_country_iso2" name="phone_country_iso2" x-model="selectedPhoneCountry" autocomplete="tel-country-code" class="w-full min-h-11 px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 text-sm focus:ring-2 focus:ring-indigo-500/30">
                                                @foreach($countries as $country)
                                                    <option value="{{ $country->iso2 }}" {{ old('phone_country_iso2', $user->profile->phone_country_iso2 ?: $user->profile->country?->iso2 ?: 'US') === $country->iso2 ? 'selected' : '' }}>{{ $country->name }} {{ $country->phone_code }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label for="phone" class="block text-xs font-semibold text-slate-400 mb-2">Mobile number</label>
                                            <input id="phone" type="tel" name="phone" value="{{ old('phone', $user->profile->phone_national_number) }}" autocomplete="tel-national" maxlength="20"
                                                class="w-full min-h-11 px-4 py-3 rounded-xl bg-white/[0.05] border @error('phone') border-red-500/50 @else border-white/[0.05] @enderror text-slate-200 text-sm focus:ring-2 focus:ring-indigo-500/30" :placeholder="phonePlaceholder">
                                            @error('phone')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                                        </div>
                                    </div>
                                    @if($user->profile->phone_e164)
                                        <p class="mt-3 text-xs {{ $user->profile->phone_verified_at ? 'text-emerald-300' : 'text-amber-300' }}" role="status">
                                            {{ app(\App\Services\Phone\PhoneNumberService::class)->masked($user->profile->phone_e164) }} — {{ $user->profile->phone_verified_at ? 'Verified' : 'Not verified' }}
                                        </p>
                                    @endif
                                </div>

                                {{-- Email (frozen — cannot be changed) --}}
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Email Address</label>
                                    <div class="relative">
                                        <input type="email" value="{{ $user->email }}" readonly disabled
                                            class="w-full px-4 py-3 rounded-xl bg-white/[0.03] border border-white/[0.05] text-slate-400 text-sm cursor-not-allowed pr-10">
                                        <svg class="w-4 h-4 text-slate-600 absolute right-3.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                        </svg>
                                    </div>
                                    <p class="mt-1.5 text-xs text-slate-400">Your email address cannot be changed. Contact support if you need to update it.</p>
                                </div>

                                {{-- Gender --}}
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Gender</label>
                                    <select name="gender"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all appearance-none">
                                        <option value="" class="bg-[#0d1117]">— Select —</option>
                                        @foreach(['male' => 'Male', 'female' => 'Female', 'other' => 'Other', 'prefer_not_to_say' => 'Prefer not to say'] as $val => $label)
                                            <option value="{{ $val }}" class="bg-[#0d1117]" {{ old('gender', $user->profile->gender) === $val ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                {{-- Date of Birth --}}
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Date of Birth</label>
                                    <input type="date" name="date_of_birth"
                                        value="{{ old('date_of_birth', $user->profile->date_of_birth?->format('Y-m-d')) }}"
                                        max="{{ now()->subDay()->format('Y-m-d') }}" autocomplete="bday"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all [color-scheme:dark]">
                                </div>
                            </div>

                            @if($portalAudience === \App\Enums\PortalAudience::Instructor)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-5 border-t border-white/[0.05] pt-5">
                                {{-- Headline --}}
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Headline</label>
                                    <input type="text" name="headline" value="{{ old('headline', $user->profile->headline) }}"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all"
                                        placeholder="e.g. Senior Mathematics Instructor">
                                </div>

                                {{-- Designation --}}
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Designation</label>
                                    <input type="text" name="designation" value="{{ old('designation', $user->profile->designation) }}"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all"
                                        placeholder="e.g. Mathematics Instructor">
                                </div>

                                {{-- Short Bio --}}
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Short Bio</label>
                                    <input type="text" name="short_bio" value="{{ old('short_bio', $user->profile->short_bio) }}" maxlength="160"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all"
                                        placeholder="Summarize the subjects and levels you teach">
                                </div>

                                {{-- Bio --}}
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Bio</label>
                                    <textarea name="bio" rows="4" maxlength="2000"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all"
                                        placeholder="Describe your teaching experience, approach, and what students can expect">{{ old('bio', $user->profile->bio) }}</textarea>
                                </div>
                                <div class="sm:col-span-2 rounded-xl border border-indigo-400/15 bg-indigo-500/[0.05] p-4 text-sm leading-6 text-slate-300">Teaching philosophy, subjects, education levels, languages, experience, education, certifications, introduction video, and verification documents are managed in <a href="{{ route('dashboard.instructor.onboarding') }}" class="font-semibold text-indigo-300 hover:text-indigo-200">Teaching profile setup</a>.</div>
                            </div>
                            @endif
                        </div>

                        @if($portalAudience === \App\Enums\PortalAudience::Student)
                            {{-- Student Preferences --}}
                            <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-5 sm:p-7 mb-5">
                                <div class="flex items-center gap-3 mb-6 pb-5 border-b border-white/[0.04]">
                                    <div class="w-9 h-9 rounded-xl bg-emerald-500/15 border border-emerald-500/25 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-4.5 h-4.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5S19.832 5.477 21 6.253v13C19.832 18.477 18.246 18 16.5 18s-3.332.477-4.5 1.253"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <h2 class="text-base font-semibold text-white">Learning Profile</h2>
                                        <p class="text-xs text-slate-400">Used for learning goals, dashboard guidance, and future instructor recommendations</p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-400 mb-2">Current Academic Level</label>
                                        <select name="student_academic_level_id"
                                            class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border @error('student_academic_level_id') border-red-500/50 @else border-white/[0.05] @enderror text-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all appearance-none">
                                            <option value="" class="bg-[#0d1117]">— Select academic level —</option>
                                            @foreach($academicLevels as $level)
                                                <option value="{{ $level->id }}" class="bg-[#0d1117]" {{ old('student_academic_level_id', $user->profile->student_academic_level_id) === $level->id ? 'selected' : '' }}>
                                                    {{ $level->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('student_academic_level_id')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                                    </div>

                                    <div>
                                        <label class="block text-xs font-semibold text-slate-400 mb-2">Preferred Lesson Language</label>
                                        <select name="student_preferred_language_id"
                                            class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border @error('student_preferred_language_id') border-red-500/50 @else border-white/[0.05] @enderror text-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all appearance-none">
                                            <option value="" class="bg-[#0d1117]">— Select preferred language —</option>
                                            @foreach($languages as $language)
                                                <option value="{{ $language->id }}" class="bg-[#0d1117]" {{ (string) old('student_preferred_language_id', $user->profile->student_preferred_language_id) === (string) $language->id ? 'selected' : '' }}>
                                                    {{ $language->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('student_preferred_language_id')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                                    </div>
                                </div>

                                <div class="mt-5">
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Preferred Subjects</label>
                                    @php
                                        $selectedPreferredSubjects = collect(old('preferred_subject_ids', $user->preferredSubjects->pluck('id')->all()))
                                            ->map(fn ($id) => (string) $id)
                                            ->all();
                                    @endphp
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                        @foreach($subjects as $subject)
                                            <label class="flex items-center gap-3 rounded-xl border border-white/[0.05] bg-white/[0.035] px-4 py-3 text-sm text-slate-200">
                                                <input type="checkbox" name="preferred_subject_ids[]" value="{{ $subject->id }}"
                                                    @checked(in_array((string) $subject->id, $selectedPreferredSubjects, true))
                                                    class="rounded border-white/[0.20] bg-slate-950 text-indigo-600 focus:ring-indigo-500/40">
                                                <span>{{ $subject->name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <p class="mt-2 text-xs text-slate-500">Subjects come from the academic master catalog, not free text.</p>
                                    @error('preferred_subject_ids')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                                    @error('preferred_subject_ids.*')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        @endif

                        {{-- Regional settings and address --}}
                        <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-5 sm:p-7 mb-5">
                            <div class="flex items-center gap-3 mb-6 pb-5 border-b border-white/[0.04]">
                                <div class="w-9 h-9 rounded-xl bg-violet-500/15 border border-violet-500/25 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4.5 h-4.5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="text-base font-semibold text-white">Region &amp; Timezone</h2>
                                    <p class="text-xs text-slate-400">Controls lesson times{{ $portalAudience === \App\Enums\PortalAudience::Student ? ', pricing, and billing currency' : ' and regional availability' }}</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                                <div class="sm:col-span-2 lg:col-span-3">
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Street Address</label>
                                    <input type="text" name="address" value="{{ old('address', $user->profile->address) }}" autocomplete="street-address"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all"
                                        placeholder="Enter street address and apartment or unit">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Country of residence</label>
                                    @if($billingCountryChangeBlocked)<input type="hidden" name="country_id" value="{{ $user->profile->country_id }}">@endif
                                    <select name="country_id" x-model="selectedCountryId" autocomplete="country" @disabled($billingCountryChangeBlocked)
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border @error('country_id') border-red-500/50 @else border-white/[0.05] @enderror text-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all appearance-none disabled:cursor-not-allowed disabled:opacity-60">
                                        <option value="" class="bg-[#0d1117]">— Select Country —</option>
                                        @foreach($countries as $country)
                                            <option value="{{ $country->id }}" class="bg-[#0d1117]">
                                                {{ $country->flag }} {{ $country->name }} — {{ $country->defaultCurrency->code }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @if($portalAudience === \App\Enums\PortalAudience::Student)
                                        <p class="mt-2 text-xs {{ $billingCountryChangeBlocked ? 'text-amber-300' : 'text-slate-400' }}">{{ $billingCountryChangeBlocked ? 'Locked because active classes or wallet history exist. Contact support to request a change.' : 'Your billing currency is derived from this country.' }}</p>
                                    @endif
                                    @error('country_id')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">State / Province</label>
                                    <select name="state_id" autocomplete="address-level1"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all appearance-none">
                                        <option value="" class="bg-[#0d1117]">— Select State —</option>
                                        <template x-for="state in statesForSelectedCountry" :key="state.id">
                                            <option :value="state.id" class="bg-[#0d1117]" x-text="state.name"
                                                :selected="String(state.id) === '{{ old('state_id', $user->profile->state_id) }}'"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">City</label>
                                    <input type="text" name="city" value="{{ old('city', $user->profile->city) }}" autocomplete="address-level2"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all" placeholder="Enter your city">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Postal Code</label>
                                    <input type="text" name="postal_code" value="{{ old('postal_code', $user->profile->postal_code) }}" autocomplete="postal-code"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all" placeholder="Enter postal or ZIP code">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Timezone <span class="text-red-400">*</span></label>
                                    <select name="timezone" required class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border @error('timezone') border-red-500/50 @else border-white/[0.05] @enderror text-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 appearance-none">
                                        <option value="">— Select your timezone —</option>
                                        @foreach($timezones as $timezone)<option value="{{ $timezone }}" @selected(old('timezone', $user->profile->timezone) === $timezone)>{{ str_replace('_', ' ', $timezone) }}</option>@endforeach
                                    </select>
                                    <p class="mt-2 text-xs text-slate-400">All lesson schedules are displayed in this timezone.</p>
                                    @error('timezone')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">Interface Language</label>
                                    <select name="language" class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 appearance-none">
                                        @foreach($languages as $language)<option value="{{ $language->code }}" @selected(old('language', $user->profile->language ?: 'en') === $language->code)>{{ $language->name }}</option>@endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        @if($portalAudience === \App\Enums\PortalAudience::Instructor)
                        {{-- Public professional links --}}
                        <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-7 mb-5">
                            <div class="flex items-center gap-3 mb-6 pb-5 border-b border-white/[0.04]">
                                <div class="w-9 h-9 rounded-xl bg-sky-500/15 border border-sky-500/25 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4.5 h-4.5 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="text-base font-semibold text-white">Professional Links</h2>
                                    <p class="text-xs text-slate-400">Optional links shown on your public instructor profile when visibility allows</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                @foreach([
                                    ['website', 'Website', 'https://example.com'],
                                    ['facebook', 'Facebook', 'https://facebook.com/username'],
                                    ['twitter', 'Twitter / X', 'https://x.com/username'],
                                    ['linkedin', 'LinkedIn', 'https://linkedin.com/in/username'],
                                    ['github', 'GitHub', 'https://github.com/username'],
                                    ['instagram', 'Instagram', 'https://instagram.com/username'],
                                    ['youtube', 'YouTube', 'https://youtube.com/@username'],
                                ] as [$field, $label, $placeholder])
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">{{ $label }}</label>
                                    <input type="url" name="{{ $field }}" value="{{ old($field, $user->profile->$field) }}"
                                        class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:border-indigo-500/30 transition-all"
                                        placeholder="{{ $placeholder }}">
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endif

                        <div class="flex items-center gap-3">
                            <button type="submit"
                                class="px-7 py-3 rounded-xl font-semibold text-sm text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 shadow-lg shadow-indigo-500/25 transition-all active:scale-[.98]">
                                Save profile
                            </button>
                            <span class="text-xs text-slate-400">Changes are saved immediately</span>
                        </div>
                    </form>

                    @if($portalAudience === \App\Enums\PortalAudience::Student && $user->profile->phone_e164 && ! $user->profile->phone_verified_at)
                        <div class="rounded-2xl border border-indigo-400/20 bg-indigo-500/[0.06] p-5 mb-5" aria-labelledby="phone-verification-title">
                            <h2 id="phone-verification-title" class="text-base font-semibold text-white">Verify mobile number</h2>
                            <p class="mt-2 text-sm text-slate-300">Verify {{ app(\App\Services\Phone\PhoneNumberService::class)->masked($user->profile->phone_e164) }} before your first paid booking.</p>
                            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                                <form method="POST" action="{{ route('profile.phone.verification.send') }}">@csrf
                                    <button class="min-h-11 rounded-xl border border-indigo-400/30 px-4 text-sm font-semibold text-indigo-200">Send code</button>
                                </form>
                                <form method="POST" action="{{ route('profile.phone.verification.verify') }}" class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-end">@csrf
                                    <div class="flex-1"><label for="otp" class="block text-xs font-semibold text-slate-300 mb-2">Six-digit code</label><input id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="w-full min-h-11 rounded-xl bg-white/[0.05] border border-white/10 px-4 text-white" required></div>
                                    <button class="min-h-11 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white">Verify number</button>
                                </form>
                            </div>
                            @error('otp')<p class="mt-2 text-xs text-red-400" role="alert">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    {{-- Profile Visibility --}}
                    <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-7 mt-5">
                        <div class="flex items-center gap-3 mb-6 pb-5 border-b border-white/[0.04]">
                            <div class="w-9 h-9 rounded-xl bg-emerald-500/15 border border-emerald-500/25 flex items-center justify-center flex-shrink-0">
                                <svg class="w-4.5 h-4.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-white">Profile Visibility</h2>
                                <p class="text-xs text-slate-400">Control who can see your profile and which details are shown</p>
                            </div>
                        </div>

                        <form method="POST" action="{{ route('profile.visibility.update') }}" class="space-y-5">
                            @csrf
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 mb-2">Who can see your profile</label>
                                <select name="profile_visibility"
                                    class="w-full sm:w-64 px-4 py-3 rounded-xl bg-white/[0.05] border border-white/[0.05] text-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500/30 transition-all appearance-none">
                                    @foreach(['public' => 'Public', 'members_only' => 'Members Only', 'private' => 'Private'] as $val => $label)
                                        <option value="{{ $val }}" class="bg-[#0d1117]" {{ $user->profile->profile_visibility === $val ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="space-y-3">
                                @foreach([
                                    ['show_email', 'Show email on profile', $user->profile->show_email],
                                    ['show_phone', 'Show phone on profile', $user->profile->show_phone],
                                    ['show_social_links', 'Show social links on profile', $user->profile->show_social_links],
                                ] as [$field, $label, $enabled])
                                <label class="flex items-center justify-between gap-4 p-4 rounded-xl border border-white/[0.04] hover:border-white/[0.08] hover:bg-white/[0.02] cursor-pointer transition-all">
                                    <span class="text-sm font-medium text-slate-200">{{ $label }}</span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="{{ $field }}" value="1" {{ $enabled ? 'checked' : '' }}>
                                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                                    </label>
                                </label>
                                @endforeach
                            </div>

                            <button type="submit"
                                class="px-7 py-3 rounded-xl font-semibold text-sm text-white bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 shadow-lg shadow-emerald-500/20 transition-all active:scale-[.98]">
                                Save Visibility
                            </button>
                        </form>
                    </div>
                </div>

                {{-- ══════════════════════════════════════════════════════ --}}
                {{-- TAB: SECURITY                                          --}}
                {{-- ══════════════════════════════════════════════════════ --}}
                <div x-show="activeTab === 'security'"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     style="display:none">

                    {{-- Security Alerts --}}
                    <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-7 mb-5">
                        <div class="flex items-center gap-3 mb-6 pb-5 border-b border-white/[0.04]">
                            <div class="w-9 h-9 rounded-xl bg-blue-500/15 border border-blue-500/25 flex items-center justify-center flex-shrink-0">
                                <svg class="w-4.5 h-4.5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-white">Login Alerts</h2>
                                <p class="text-xs text-slate-400">Get notified when your account is accessed</p>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('profile.security.alerts') }}" class="space-y-3">
                            @csrf
                            @foreach([
                                ['login_alerts_enabled', 'Login alert emails', 'Receive an email every time someone signs in to your account', $user->login_alerts_enabled],
                                ['new_device_alerts_enabled', 'New device alerts', 'Get notified when a new browser or device signs in', $user->new_device_alerts_enabled],
                            ] as [$field, $label, $desc, $enabled])
                            <label class="flex items-center justify-between gap-4 p-4 rounded-xl border border-white/[0.04] hover:border-white/[0.08] hover:bg-white/[0.02] cursor-pointer transition-all">
                                <div>
                                    <p class="text-sm font-medium text-slate-200">{{ $label }}</p>
                                    <p class="text-xs text-slate-400 mt-0.5">{{ $desc }}</p>
                                </div>
                                <div class="flex-shrink-0">
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="{{ $field }}" value="1" {{ $enabled ? 'checked' : '' }} onchange="this.form.submit()">
                                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                                    </label>
                                </div>
                            </label>
                            @endforeach
                        </form>
                    </div>

                    {{-- Change Password --}}
                    <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-7 mb-5"
                         x-data="{ showCurrent: false, showNew: false, showConfirm: false }">
                        <div class="flex items-center gap-3 mb-6 pb-5 border-b border-white/[0.04]">
                            <div class="w-9 h-9 rounded-xl bg-amber-500/15 border border-amber-500/25 flex items-center justify-center flex-shrink-0">
                                <svg class="w-4.5 h-4.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h2 class="text-base font-semibold text-white">Change Password</h2>
                                <p class="text-xs text-slate-400">
                                    Last changed: <span class="text-slate-400">{{ $user->password_changed_at?->diffForHumans() ?? 'Never' }}</span>
                                </p>
                            </div>
                        </div>

                        <form method="POST" action="{{ route('profile.password') }}">
                            @csrf
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-5">
                                @foreach([
                                    ['current_password', 'showCurrent', 'Current Password', 'current-password', '••••••••'],
                                    ['password',         'showNew',     'New Password',      'new-password',     'Min. 8 characters'],
                                    ['password_confirmation', 'showConfirm', 'Confirm Password', 'new-password', 'Repeat new password'],
                                ] as [$name, $showVar, $label, $autocomplete, $placeholder])
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 mb-2">{{ $label }}</label>
                                    <div class="relative">
                                        <input :type="{{ $showVar }} ? 'text' : 'password'" name="{{ $name }}"
                                            class="w-full pr-10 px-4 py-3 rounded-xl bg-white/[0.05] border @error($name) border-red-500/50 @else border-white/[0.05] @enderror text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500/25 focus:border-amber-500/30 transition-all"
                                            placeholder="{{ $placeholder }}" autocomplete="{{ $autocomplete }}">
                                        <button type="button" @click="{{ $showVar }} = !{{ $showVar }}"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-300 transition-colors">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path x-show="!{{ $showVar }}" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                <path x-show="{{ $showVar }}" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" style="display:none"/>
                                            </svg>
                                        </button>
                                    </div>
                                    @error($name)<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                                </div>
                                @endforeach
                            </div>

                            <div class="p-4 rounded-xl bg-white/[0.02] border border-white/[0.06] mb-5">
                                <p class="text-xs text-slate-400 font-semibold mb-2.5">Password requirements</p>
                                <ul class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1.5">
                                    @foreach(['At least 8 characters', 'Uppercase & lowercase', 'At least one number', 'At least one symbol'] as $rule)
                                    <li class="flex items-center gap-1.5 text-xs text-slate-400">
                                        <svg class="w-3 h-3 text-slate-700 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                        </svg>
                                        {{ $rule }}
                                    </li>
                                    @endforeach
                                </ul>
                            </div>

                            <button type="submit"
                                class="px-7 py-3 rounded-xl font-semibold text-sm text-white bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 shadow-lg shadow-amber-500/20 transition-all active:scale-[.98]">
                                Update Password
                            </button>
                        </form>
                    </div>

                    {{-- Login History --}}
                    <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-7 mb-5">
                        <div class="flex items-center gap-3 mb-6 pb-5 border-b border-white/[0.04]">
                            <div class="w-9 h-9 rounded-xl bg-slate-700/50 border border-white/[0.05] flex items-center justify-center flex-shrink-0">
                                <svg class="w-4.5 h-4.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-white">Recent Login Activity</h2>
                                <p class="text-xs text-slate-400">Last 10 login attempts on your account</p>
                            </div>
                        </div>
                        @if($loginHistory->isEmpty())
                            <div class="py-10 text-center">
                                <svg class="w-10 h-10 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <p class="text-sm text-slate-400">No login history yet.</p>
                            </div>
                        @else
                            <div class="space-y-1">
                                @foreach($loginHistory as $log)
                                <div class="flex items-center justify-between py-3 px-4 rounded-xl hover:bg-white/[0.02] transition-colors border border-transparent hover:border-white/[0.05]">
                                    <div class="flex items-center gap-3">
                                        <div class="w-2 h-2 rounded-full flex-shrink-0 {{ $log->isSuccessful() ? 'bg-emerald-400' : 'bg-red-400' }}"></div>
                                        <div>
                                            <p class="text-sm text-slate-300">{{ $log->browser }} on {{ $log->platform }}</p>
                                            <p class="text-xs text-slate-400">{{ $log->ip_address }} · {{ $log->device_type }}</p>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <p class="text-xs text-slate-400">{{ $log->logged_in_at?->diffForHumans() }}</p>
                                        <span class="text-xs px-2 py-0.5 rounded-full {{ $log->isSuccessful() ? 'bg-emerald-500/15 text-emerald-400' : 'bg-red-500/15 text-red-400' }}">
                                            {{ ucfirst($log->status) }}
                                        </span>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Active Sessions --}}
                    <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-7"
                         x-data="{
                            sessions: @js($activeSessions->map(fn($s) => [
                                'id'           => $s->session_id,
                                'browser'      => $s->browser ?? 'Unknown',
                                'platform'     => $s->platform ?? 'Unknown',
                                'device_type'  => $s->device_type ?? 'desktop',
                                'ip_address'   => $s->ip_address ?? '—',
                                'last_seen'    => $s->last_activity_at?->diffForHumans() ?? 'Unknown',
                                'created_at'   => $s->created_at?->format('d M Y, h:i A') ?? '—',
                                'is_current'   => $s->isCurrent('{{ $currentSessionId }}'),
                            ])->values()->toArray()),
                            revoking: null,
                            revokingAll: false,
                            flashMsg: '',
                            flashType: '',
                            deviceIcon(type) {
                                if (type === 'mobile')  return '📱';
                                if (type === 'tablet')  return '⬜';
                                if (type === 'desktop') return '🖥️';
                                return '🌐';
                            },
                            async revokeSession(sessionId) {
                                this.revoking = sessionId;
                                try {
                                    const res = await fetch('{{ route('profile.sessions.revoke', ':id') }}'.replace(':id', sessionId), {
                                        method: 'DELETE',
                                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
                                    });
                                    const data = await res.json();
                                    if (data.success) { this.sessions = this.sessions.filter(s => s.id !== sessionId); this.flash('Session revoked.', 'success'); }
                                    else { this.flash(data.message || 'Failed.', 'error'); }
                                } catch (e) { this.flash('Network error.', 'error'); }
                                finally { this.revoking = null; }
                            },
                            async revokeAll() {
                                if (!confirm('Revoke all other sessions?')) return;
                                this.revokingAll = true;
                                try {
                                    const res = await fetch('{{ route('profile.sessions.revoke-all') }}', {
                                        method: 'DELETE',
                                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
                                    });
                                    const data = await res.json();
                                    if (data.success) { this.sessions = this.sessions.filter(s => s.is_current); this.flash(data.message, 'success'); }
                                    else { this.flash('Failed.', 'error'); }
                                } catch (e) { this.flash('Network error.', 'error'); }
                                finally { this.revokingAll = false; }
                            },
                            flash(msg, type) { this.flashMsg = msg; this.flashType = type; setTimeout(() => { this.flashMsg = ''; }, 4000); }
                         }">
                        <div class="flex items-center justify-between gap-4 mb-6 pb-5 border-b border-white/[0.04]">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-indigo-500/15 border border-indigo-500/25 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4.5 h-4.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2"/>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="text-base font-semibold text-white">Active Sessions</h2>
                                    <p class="text-xs text-slate-400">Devices signed in to your account</p>
                                </div>
                            </div>
                            <button @click="revokeAll()"
                                :disabled="revokingAll || sessions.filter(s => !s.is_current).length === 0"
                                class="flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold text-red-400 border border-red-500/30 hover:bg-red-500/10 disabled:opacity-40 disabled:cursor-not-allowed transition">
                                <svg class="w-3.5 h-3.5" :class="revokingAll ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                </svg>
                                <span x-text="revokingAll ? 'Revoking...' : 'Revoke All Others'"></span>
                            </button>
                        </div>

                        <div x-show="flashMsg" x-transition
                             class="mb-4 flex items-center gap-2 px-4 py-3 rounded-xl text-sm"
                             :class="flashType === 'success' ? 'bg-emerald-500/10 border border-emerald-500/25 text-emerald-300' : 'bg-red-500/10 border border-red-500/25 text-red-300'"
                             style="display:none">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span x-text="flashMsg"></span>
                        </div>

                        <div class="space-y-2.5">
                            <template x-for="session in sessions" :key="session.id">
                                <div class="group flex items-center justify-between gap-4 p-4 rounded-xl border transition-all"
                                     :class="session.is_current ? 'border-indigo-500/25 bg-indigo-500/[0.04]' : 'border-white/[0.04] bg-white/[0.02] hover:border-white/[0.08] hover:bg-white/[0.04]'">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center text-lg"
                                             :class="session.is_current ? 'bg-indigo-500/15 border border-indigo-500/25' : 'bg-white/[0.05] border border-white/[0.05]'">
                                            <span x-text="deviceIcon(session.device_type)"></span>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <p class="text-sm font-medium text-slate-200 truncate" x-text="session.browser + ' on ' + session.platform"></p>
                                                <span x-show="session.is_current"
                                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 flex-shrink-0">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 animate-pulse inline-block"></span>
                                                    This device
                                                </span>
                                            </div>
                                            <p class="text-xs text-slate-400 mt-0.5">
                                                <span x-text="session.ip_address"></span>
                                                &nbsp;·&nbsp;<span class="capitalize" x-text="session.device_type"></span>
                                                &nbsp;·&nbsp;Last seen <span x-text="session.last_seen"></span>
                                            </p>
                                        </div>
                                    </div>
                                    <button x-show="!session.is_current" @click="revokeSession(session.id)" :disabled="revoking === session.id"
                                        class="flex-shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-medium text-red-400 border border-red-500/20 hover:bg-red-500/10 hover:border-red-500/40 disabled:opacity-50 opacity-0 group-hover:opacity-100 transition-all">
                                        <svg class="w-3.5 h-3.5" :class="revoking === session.id ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path x-show="revoking !== session.id" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636"/>
                                            <path x-show="revoking === session.id" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                        </svg>
                                        <span x-text="revoking === session.id ? 'Revoking…' : 'Revoke'"></span>
                                    </button>
                                    <div x-show="session.is_current" class="flex-shrink-0">
                                        <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                    </div>
                                </div>
                            </template>
                            <div x-show="sessions.length === 0" class="py-10 text-center">
                                <svg class="w-10 h-10 text-slate-700 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-2"/></svg>
                                <p class="text-sm text-slate-400">No active sessions found.</p>
                            </div>
                        </div>

                        <div class="mt-5 flex items-start gap-3 p-4 rounded-xl bg-amber-500/5 border border-amber-500/15">
                            <svg class="w-4 h-4 text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            <p class="text-xs text-amber-400/80 leading-relaxed">
                                <strong class="text-amber-300">Security tip:</strong>
                                If you see an unfamiliar session, revoke it immediately and
                                <a href="{{ route('profile.show') }}#security" class="underline decoration-dotted hover:text-amber-300 transition">change your password</a>.
                            </p>
                        </div>
                    </div>
                </div>

                {{-- ══════════════════════════════════════════════════════ --}}
                {{-- TAB: NOTIFICATIONS                                     --}}
                {{-- ══════════════════════════════════════════════════════ --}}
                <div x-show="activeTab === 'notifications'"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     style="display:none">
                    <form method="POST" action="{{ route('profile.update') }}">
                        @csrf
                        <input type="hidden" name="first_name" value="{{ $user->first_name ?? $user->name }}">

                        <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-7">
                            <div class="flex items-center gap-3 mb-6 pb-5 border-b border-white/[0.04]">
                                <div class="w-9 h-9 rounded-xl bg-sky-500/15 border border-sky-500/25 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4.5 h-4.5 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="text-base font-semibold text-white">Notification Preferences</h2>
                                    <p class="text-xs text-slate-400">Control which notifications you receive</p>
                                </div>
                            </div>

                            @php $notifPrefs = $user->profile->notification_preferences ?? []; @endphp

                            <div class="space-y-3">
                                @foreach([
                                    ['email_notifications',  'Email Notifications', 'Account alerts, course updates, and important messages', 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z', 'indigo'],
                                    ['system_notifications', 'System Notifications', 'In-app notifications for activity and updates', 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'violet'],
                                    ['marketing_emails',     'Marketing Emails', 'Promotions, new courses, and special offers', 'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z', 'amber'],
                                ] as [$key, $label, $description, $icon, $color])
                                <div class="flex items-center justify-between p-5 rounded-xl border border-white/[0.04] hover:border-white/[0.08] hover:bg-white/[0.02] transition-all"
                                     x-data="{ checked: {{ ($notifPrefs[$key] ?? true) ? 'true' : 'false' }} }">
                                    <div class="flex items-start gap-4">
                                        <div class="w-10 h-10 rounded-xl bg-{{ $color }}-500/15 border border-{{ $color }}-500/25 flex items-center justify-center flex-shrink-0 mt-0.5">
                                            <svg class="w-5 h-5 text-{{ $color }}-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-slate-200">{{ $label }}</p>
                                            <p class="text-xs text-slate-400 mt-0.5">{{ $description }}</p>
                                        </div>
                                    </div>
                                    <label class="relative flex items-center cursor-pointer flex-shrink-0 ml-4">
                                        <input type="hidden" name="{{ $key }}" :value="checked ? '1' : '0'">
                                        <input type="checkbox" class="sr-only peer" x-model="checked">
                                        <div class="w-11 h-6 bg-white/10 border border-white/20 rounded-full peer-checked:bg-indigo-600 peer-checked:border-indigo-500 transition-all"></div>
                                        <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow transition-all peer-checked:translate-x-5"></div>
                                    </label>
                                </div>
                                @endforeach
                            </div>

                            <div class="mt-6">
                                <button type="submit"
                                    class="px-7 py-3 rounded-xl font-semibold text-sm text-white bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-500 hover:to-indigo-500 shadow-lg shadow-sky-500/20 transition-all active:scale-[.98]">
                                    Save Preferences
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

        </div>{{-- /tab content --}}

        {{-- Bottom padding --}}
        <div class="h-8"></div>
    </div>{{-- /container --}}
</div>
@endsection
