@props([
    'notificationCount' => 0,
    'profileSummary' => null,
    'walletEnabled' => false,
    'walletSummary' => null,
    'referralEnabled' => false,
])

<header class="sticky top-0 z-40 bg-slate-950/90 shadow-lg shadow-black/10 backdrop-blur-xl" data-account-header>
    <div class="mx-auto flex min-h-16 max-w-screen-2xl items-center gap-3 px-4 sm:px-6">
        <button type="button" x-ref="menuTrigger" @click="openDrawer()"
                class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-slate-200 hover:bg-white/[0.08] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 lg:hidden"
                aria-label="Open account navigation" aria-controls="account-navigation-drawer" :aria-expanded="drawerOpen.toString()">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>

        @php
            $portalGeneralSettings = app(\App\Settings\GeneralSettings::class);
            $portalBadgeLetter = mb_substr($portalGeneralSettings->app_short_name ?: $portalGeneralSettings->app_name, 0, 1);
        @endphp
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[rgb(var(--portal-a))] via-indigo-500 to-[rgb(var(--portal-c))] text-sm font-black text-white shadow-lg shadow-[rgb(var(--portal-a)/.18)]" aria-hidden="true">{{ $portalBadgeLetter }}</span>
            <span class="hidden truncate text-sm font-bold text-white sm:block">{{ config('app.name') }} Portal</span>
        </a>

        <div class="ml-auto flex items-center gap-2">
            <a href="{{ route('home') }}" class="hidden min-h-11 items-center rounded-xl px-3 text-sm font-semibold text-slate-300 transition-colors hover:bg-white/[0.08] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 sm:inline-flex">
                Explore website
            </a>

            <a href="{{ route('dashboard.notifications') }}" class="relative inline-flex h-11 w-11 items-center justify-center rounded-xl text-slate-200 hover:bg-white/[0.08] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400" aria-label="Notifications{{ $notificationCount ? ', '.$notificationCount.' unread' : '' }}">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h11zm0 0v1a3 3 0 11-6 0v-1"/></svg>
                @if($notificationCount)
                    <span class="absolute right-1 top-1 inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white">{{ $notificationCount > 99 ? '99+' : $notificationCount }}</span>
                @endif
            </a>

            <div class="relative" x-data="{ accountMenuOpen: false }" @keydown.escape.window="accountMenuOpen = false">
                <button type="button"
                        @click="accountMenuOpen = ! accountMenuOpen"
                        @click.outside="accountMenuOpen = false"
                        class="inline-flex min-h-11 max-w-48 items-center gap-2 rounded-xl px-2 text-slate-200 transition-colors hover:bg-white/[0.08] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"
                        aria-haspopup="menu"
                        :aria-expanded="accountMenuOpen.toString()"
                        aria-controls="account-header-menu">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-slate-700 text-xs font-bold">
                        @if($profileSummary?->avatarThumbUrl)
                            <img src="{{ $profileSummary->avatarThumbUrl }}" alt="" class="h-full w-full object-cover">
                        @else
                            {{ strtoupper(mb_substr(auth()->user()->first_name ?? auth()->user()->name, 0, 1)) }}
                        @endif
                    </span>
                    <span class="hidden truncate text-sm font-semibold md:block">{{ auth()->user()->first_name ?? auth()->user()->name }}</span>
                    <svg class="hidden h-4 w-4 shrink-0 text-slate-400 transition-transform md:block" :class="accountMenuOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/></svg>
                    <span class="sr-only">Open account menu</span>
                </button>

                <div x-cloak x-show="accountMenuOpen" x-transition.origin.top.right
                     id="account-header-menu" role="menu" aria-label="Account menu"
                     class="absolute right-0 mt-2 w-[min(20rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-white/[0.10] bg-slate-950 p-2 shadow-2xl shadow-black/40">
                    <div class="border-b border-white/[0.08] px-3 py-3">
                        <p class="truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</p>
                    </div>

                    @if($walletEnabled)
                        <a href="{{ route('dashboard.wallet') }}" role="menuitem" class="my-2 block rounded-xl bg-emerald-500/[0.08] px-3 py-3 transition-colors hover:bg-emerald-500/[0.13] focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-emerald-300">Available wallet balance</span>
                            <span class="mt-1 block text-lg font-bold text-white">{{ $walletSummary?->availableBalance ?? 'No wallet yet' }}</span>
                            <span class="mt-1 block text-xs text-slate-400">View wallet and statement →</span>
                        </a>
                    @endif

                    <nav class="space-y-1 py-1" aria-label="Account shortcuts">
                        <a href="{{ route('dashboard') }}" role="menuitem" class="flex min-h-11 items-center rounded-xl px-3 text-sm font-medium text-slate-200 hover:bg-white/[0.07] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">Dashboard</a>
                        <a href="{{ route('profile.show') }}" role="menuitem" class="flex min-h-11 items-center rounded-xl px-3 text-sm font-medium text-slate-200 hover:bg-white/[0.07] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">My profile</a>
                        <a href="{{ route('dashboard.notifications') }}" role="menuitem" class="flex min-h-11 items-center justify-between rounded-xl px-3 text-sm font-medium text-slate-200 hover:bg-white/[0.07] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300"><span>Notifications</span>@if($notificationCount)<span class="rounded-full bg-rose-500/15 px-2 py-0.5 text-xs font-bold text-rose-300">{{ $notificationCount > 99 ? '99+' : $notificationCount }}</span>@endif</a>
                        @if($referralEnabled)
                            <a href="{{ route('dashboard.refer-a-friend') }}" role="menuitem" class="flex min-h-11 items-center rounded-xl px-3 text-sm font-medium text-slate-200 hover:bg-white/[0.07] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">Earn wallet credits</a>
                        @endif
                        <a href="{{ route('home') }}" role="menuitem" class="flex min-h-11 items-center rounded-xl px-3 text-sm font-medium text-slate-200 hover:bg-white/[0.07] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">Explore website</a>
                    </nav>

                    <form method="POST" action="{{ route('auth.logout') }}" class="border-t border-white/[0.08] pt-2">
                        @csrf
                        <button type="submit" role="menuitem" class="flex min-h-11 w-full items-center rounded-xl px-3 text-left text-sm font-semibold text-rose-300 hover:bg-rose-500/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-300">Sign out</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-[rgb(var(--portal-a)/.85)] to-transparent shadow-[0_1px_12px_rgb(var(--portal-b)/.45)]" aria-hidden="true"></div>
</header>
