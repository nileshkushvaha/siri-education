@props(['notificationCount' => 0, 'profileSummary' => null])

<header class="sticky top-0 z-40 border-b border-white/[0.08] bg-slate-950/95 backdrop-blur" data-account-header>
    <div class="mx-auto flex min-h-16 max-w-screen-2xl items-center gap-3 px-4 sm:px-6">
        <button type="button" x-ref="menuTrigger" @click="openDrawer()"
                class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-slate-200 hover:bg-white/[0.08] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 lg:hidden"
                aria-label="Open account navigation" aria-controls="account-navigation-drawer" :aria-expanded="drawerOpen.toString()">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>

        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-500 text-sm font-black text-white" aria-hidden="true">{{ mb_substr(config('app.name'), 0, 1) }}</span>
            <span class="hidden truncate text-sm font-bold text-white sm:block">{{ config('app.name') }} Portal</span>
        </a>

        <div class="ml-auto flex items-center gap-2">
            <a href="{{ route('dashboard.notifications') }}" class="relative inline-flex h-11 w-11 items-center justify-center rounded-xl text-slate-200 hover:bg-white/[0.08] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400" aria-label="Notifications{{ $notificationCount ? ', '.$notificationCount.' unread' : '' }}">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h11zm0 0v1a3 3 0 11-6 0v-1"/></svg>
                @if($notificationCount)
                    <span class="absolute right-1 top-1 inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white">{{ $notificationCount > 99 ? '99+' : $notificationCount }}</span>
                @endif
            </a>
            <a href="{{ route('profile.show') }}" class="inline-flex min-h-11 max-w-48 items-center gap-2 rounded-xl px-2 text-slate-200 hover:bg-white/[0.08] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400" aria-label="Open account profile">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-slate-700 text-xs font-bold">
                    @if($profileSummary?->avatarUrl)
                        <img src="{{ $profileSummary->avatarUrl }}" alt="" class="h-full w-full object-cover">
                    @else
                        {{ strtoupper(mb_substr(auth()->user()->first_name ?? auth()->user()->name, 0, 1)) }}
                    @endif
                </span>
                <span class="hidden truncate text-sm font-semibold md:block">{{ auth()->user()->first_name ?? auth()->user()->name }}</span>
            </a>
        </div>
    </div>
</header>
