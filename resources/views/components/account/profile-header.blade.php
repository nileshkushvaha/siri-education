@props(['summary', 'variant' => 'compact'])

@if($variant === 'full')
    {{-- ── FULL: Profile page hero banner (cover + overlapping avatar) ─── --}}
    <div class="relative rounded-2xl border border-white/[0.05] overflow-hidden mb-6" data-account-profile-header data-variant="full">
        {{-- Cover --}}
        <div class="h-36 sm:h-48 relative group/cover"
             style="{{ $summary->coverUrl ? '' : 'background:linear-gradient(135deg,rgba(99,102,241,.18) 0%,rgba(139,92,246,.14) 50%,rgba(59,130,246,.10) 100%);' }}">
            @if($summary->coverUrl)
                <img src="{{ $summary->coverUrl }}" class="w-full h-full object-cover" alt="Cover">
            @endif
            {{ $coverActions ?? '' }}
        </div>

        <div class="relative z-10 p-6 pt-0 sm:p-8 sm:pt-0">
            <div class="-mt-10 flex flex-col items-start gap-5 sm:-mt-12 sm:flex-row sm:items-start sm:gap-6">
                <div class="relative z-20 group flex-shrink-0">
                    <div class="w-20 h-20 rounded-2xl overflow-hidden border-4 border-surface-dark bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-xl shadow-indigo-500/20">
                        {{ $avatar ?? '' }}
                        @if(!isset($avatar))
                            @if($summary->avatarUrl)
                                <img src="{{ $summary->avatarUrl }}" class="w-full h-full object-cover" alt="{{ $summary->name }}">
                            @else
                                <span class="text-3xl font-bold text-white">{{ $summary->initial }}</span>
                            @endif
                        @endif
                    </div>
                    {{ $avatarActions ?? '' }}
                    @if($summary->online)
                        <span class="absolute -bottom-1 -right-1 w-4 h-4 rounded-full bg-emerald-400 border-2 border-surface-dark"></span>
                    @endif
                </div>

                {{-- On wider layouts only the avatar overlaps the cover. Keeping
                     identity text below the cover preserves contrast for every image. --}}
                <div class="min-w-0 flex-1 sm:mt-14">
                    <div class="flex flex-wrap items-center gap-3 mb-1">
                        <h1 class="text-2xl font-bold text-white">{{ $summary->name }}</h1>
                        @if($summary->emailVerified)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/15 text-emerald-400 border border-emerald-500/25">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                Verified
                            </span>
                        @endif
                    </div>
                    <p class="text-slate-400 text-sm mb-3">{{ $summary->email }}</p>

                    <div class="flex flex-wrap gap-4">
                        <div class="flex items-center gap-1.5 text-xs text-slate-400">
                            <svg class="w-3.5 h-3.5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            Member since {{ $summary->memberSinceHuman }}
                        </div>
                        <div class="flex items-center gap-1.5 text-xs text-slate-400">
                            <svg class="w-3.5 h-3.5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Last login {{ $summary->lastLoginHuman ?? 'Recently' }}
                        </div>
                    </div>

                    {{ $slot ?? '' }}
                </div>

                <div class="hidden flex-shrink-0 flex-col items-end gap-2 lg:mt-14 lg:flex">
                    {{ $actions ?? '' }}
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-white/[0.03] border border-white/[0.06]">
                        <span class="w-2 h-2 rounded-full {{ $summary->online ? 'bg-emerald-400 animate-pulse' : 'bg-slate-600' }}"></span>
                        <span class="text-xs text-slate-400">{{ $summary->online ? 'Online' : 'Offline' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
@else
    {{-- ── COMPACT: Dashboard sidebar card ────────────────────────────── --}}
    <div data-account-profile-header data-variant="compact">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center overflow-hidden flex-shrink-0">
                @if($summary->avatarUrl)
                    <img src="{{ $summary->avatarUrl }}" class="w-full h-full object-cover" alt="{{ $summary->name }}">
                @else
                    <span class="text-white font-bold text-lg">{{ $summary->initial }}</span>
                @endif
            </div>
            <div>
                <p class="text-white font-semibold text-sm">{{ $summary->name }}</p>
                <div class="flex items-center gap-1.5 mt-0.5">
                    <span class="w-1.5 h-1.5 rounded-full {{ $summary->online ? 'bg-emerald-400' : 'bg-slate-600' }}"></span>
                    <span class="text-xs {{ $summary->online ? 'text-emerald-400' : 'text-slate-400' }}">{{ $summary->online ? 'Active' : 'Offline' }}</span>
                </div>
            </div>
        </div>

        <div class="space-y-2 mb-4">
            <div class="flex items-center justify-between text-xs">
                <span class="text-slate-400">Profile Complete</span>
                <span class="text-slate-300 font-medium">{{ $summary->profileCompletion }}%</span>
            </div>
            <div class="h-1.5 bg-white/[0.06] rounded-full overflow-hidden">
                <div class="h-full bg-gradient-to-r from-indigo-500 to-violet-500 rounded-full" style="width: {{ $summary->profileCompletion }}%"></div>
            </div>
            <p class="text-slate-400 text-xs">Add your photo, bio and preferences to complete your profile</p>
        </div>

        {{ $actions ?? '' }}
    </div>
@endif
