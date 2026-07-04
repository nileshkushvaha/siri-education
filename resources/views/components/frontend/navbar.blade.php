{{--
    Frontend Navbar Component — <x-frontend.navbar />
    No props needed. Breadcrumbs are handled via @section('breadcrumbs') in each page.
--}}

@php
    $user = auth()->user();
    $userName   = $user?->first_name ?? explode(' ', $user?->name ?? 'User')[0];
    $userEmail  = $user?->email ?? '';
    $userAvatar = $user?->avatar_url ?? '';
@endphp

<div x-data="{
    dropdownOpen: false,
    mobileOpen: false,
    user: {
        name: {{ Js::from($userName) }},
        email: {{ Js::from($userEmail) }},
        avatar: {{ Js::from($userAvatar) }},
    }
}" @keydown.escape.window="dropdownOpen = false; mobileOpen = false">

    {{-- ── MAIN NAV ─────────────────────────────────────────────────── --}}
    <nav class="sticky top-0 z-50 border-b border-white/[0.06]"
         style="background:rgba(5,8,15,.88);backdrop-filter:blur(24px);">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">

                {{-- Logo --}}
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 flex-shrink-0">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center shadow-md shadow-indigo-500/30">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                        </svg>
                    </div>
                    <span class="text-white font-bold text-lg">{{ config('app.name') }}</span>
                </a>

                {{-- Nav Links (desktop) --}}
                @auth
                <div class="hidden md:flex items-center gap-1">
                    <a href="{{ route('dashboard') }}"
                       class="px-3 py-2 rounded-lg text-sm font-medium transition-colors
                              {{ request()->routeIs('dashboard') ? 'text-white bg-white/[0.07]' : 'text-slate-400 hover:text-white hover:bg-white/[0.04]' }}">
                        Dashboard
                    </a>
                    <a href="#" class="px-3 py-2 rounded-lg text-sm text-slate-400 hover:text-white hover:bg-white/[0.04] transition-colors">My Courses</a>
                    <a href="#" class="px-3 py-2 rounded-lg text-sm text-slate-400 hover:text-white hover:bg-white/[0.04] transition-colors">Browse</a>
                    <a href="#" class="px-3 py-2 rounded-lg text-sm text-slate-400 hover:text-white hover:bg-white/[0.04] transition-colors">Community</a>
                </div>
                @else
                <div class="hidden md:flex items-center gap-1">
                    <a href="{{ route('home') }}" class="px-3 py-2 rounded-lg text-sm text-slate-400 hover:text-white hover:bg-white/[0.04] transition-colors">Home</a>
                </div>
                @endauth

                {{-- Right side --}}
                <div class="flex items-center gap-2">
                    @auth
                    {{-- Notification bell --}}
                    <button class="w-8 h-8 rounded-lg flex items-center justify-center border border-white/[0.08] text-slate-400 hover:text-white hover:bg-white/[0.05] transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                    </button>

                    {{-- User dropdown --}}
                    <div class="relative" @click.outside="dropdownOpen = false">
                        <button @click="dropdownOpen = !dropdownOpen"
                            class="flex items-center gap-2 px-2.5 py-1.5 rounded-xl border border-white/[0.10]
                                   hover:border-white/[0.20] hover:bg-white/[0.04] transition-all duration-200">
                            <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center overflow-hidden flex-shrink-0">
                                <template x-if="user.avatar">
                                    <img :src="user.avatar" class="w-full h-full object-cover" :alt="user.name">
                                </template>
                                <template x-if="!user.avatar">
                                    <span class="text-white text-xs font-bold" x-text="user.name.charAt(0).toUpperCase()"></span>
                                </template>
                            </div>
                            <span class="text-slate-300 text-sm font-medium hidden sm:block" x-text="user.name"></span>
                            <svg class="w-3.5 h-3.5 text-slate-400 transition-transform duration-200" :class="dropdownOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        {{-- Dropdown panel --}}
                        <div x-show="dropdownOpen"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 scale-95 translate-y-1"
                             x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-100"
                             x-transition:leave-start="opacity-100 scale-100"
                             x-transition:leave-end="opacity-0 scale-95"
                             class="absolute right-0 top-full mt-2 w-56 rounded-2xl border border-white/[0.10] shadow-2xl shadow-black/60 overflow-hidden z-50"
                             style="display:none;background:rgba(8,11,22,.97);backdrop-filter:blur(24px);">

                            <div class="px-4 py-3 border-b border-white/[0.06]">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center overflow-hidden flex-shrink-0">
                                        <template x-if="user.avatar">
                                            <img :src="user.avatar" class="w-full h-full object-cover">
                                        </template>
                                        <template x-if="!user.avatar">
                                            <span class="text-white text-sm font-bold" x-text="user.name.charAt(0).toUpperCase()"></span>
                                        </template>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-white text-sm font-semibold truncate" x-text="user.name"></p>
                                        <p class="text-slate-400 text-xs truncate" x-text="user.email"></p>
                                    </div>
                                </div>
                            </div>

                            <div class="p-1.5">
                                <a href="{{ route('dashboard') }}"
                                   class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm transition-colors
                                          {{ request()->routeIs('dashboard') ? 'bg-white/[0.07] text-white' : 'text-slate-300 hover:text-white hover:bg-white/[0.06]' }}">
                                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                                    </svg>
                                    Dashboard
                                </a>
                                <a href="{{ route('profile.show') }}"
                                   class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm transition-colors
                                          {{ request()->routeIs('profile.*') ? 'bg-white/[0.07] text-white' : 'text-slate-300 hover:text-white hover:bg-white/[0.06]' }}">
                                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    My Profile
                                </a>

                                <div class="border-t border-white/[0.06] my-1.5 mx-1"></div>

                                <form method="POST" action="{{ route('auth.logout') }}">
                                    @csrf
                                    <button type="submit"
                                        class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-red-400 hover:text-red-300 hover:bg-red-500/[0.08] transition-colors text-sm text-left">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                        </svg>
                                        Sign Out
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    {{-- Mobile toggle --}}
                    <button @click="mobileOpen = !mobileOpen"
                        class="md:hidden w-8 h-8 rounded-lg flex items-center justify-center border border-white/[0.08] text-slate-400 hover:text-white transition-colors">
                        <svg x-show="!mobileOpen" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        <svg x-show="mobileOpen" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>

                    @else
                    {{-- Guest buttons --}}
                    <a href="{{ route('auth.login') }}" class="px-4 py-2 rounded-xl text-sm font-medium text-slate-300 border border-white/10 hover:border-white/20 hover:text-white transition-colors">Sign In</a>
                    <a href="{{ route('auth.register') }}" class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition shadow-lg shadow-indigo-500/25">Get Started</a>
                    @endauth
                </div>
            </div>
        </div>

        {{-- Mobile menu --}}
        @auth
        <div x-show="mobileOpen"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-end="opacity-0 -translate-y-2"
             class="md:hidden border-t border-white/[0.06] pb-3"
             style="display:none;">
            <div class="max-w-7xl mx-auto px-4 pt-2 space-y-0.5">
                <a href="{{ route('dashboard') }}" class="block px-3 py-2 rounded-xl text-sm text-slate-300 hover:text-white hover:bg-white/[0.05] transition-colors">Dashboard</a>
                <a href="#" class="block px-3 py-2 rounded-xl text-sm text-slate-300 hover:text-white hover:bg-white/[0.05] transition-colors">My Courses</a>
                <a href="#" class="block px-3 py-2 rounded-xl text-sm text-slate-300 hover:text-white hover:bg-white/[0.05] transition-colors">Browse</a>
                <a href="{{ route('profile.show') }}" class="block px-3 py-2 rounded-xl text-sm text-slate-300 hover:text-white hover:bg-white/[0.05] transition-colors">My Profile</a>
                <div class="border-t border-white/[0.06] my-1 mx-1"></div>
                <form method="POST" action="{{ route('auth.logout') }}">
                    @csrf
                    <button type="submit" class="w-full text-left px-3 py-2 rounded-xl text-sm text-red-400 hover:text-red-300 hover:bg-red-500/[0.08] transition-colors">Sign Out</button>
                </form>
            </div>
        </div>
        @endauth
    </nav>

</div>
