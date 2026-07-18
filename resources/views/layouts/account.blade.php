@extends('layouts.frontend')

@section('portal-shell', 'true')

@section('content')
@php
    $accountFullWidth = trim($__env->yieldContent('account-full-width')) === 'true';
    $mobilePrimaryItems = collect($accountMenu ?? [])->flatMap(fn (array $group) => $group['items'])
        ->filter(fn (array $item) => $item['mobile_priority'] !== null)
        ->sortBy('mobile_priority');
@endphp

<div class="min-h-screen bg-surface-dark text-slate-100"
     x-data="{
        drawerOpen: false,
        openDrawer() { this.drawerOpen = true; document.body.style.overflow = 'hidden'; this.$nextTick(() => this.$refs.drawerClose?.focus()); },
        closeDrawer() { if (!this.drawerOpen) return; this.drawerOpen = false; document.body.style.overflow = ''; this.$nextTick(() => this.$refs.menuTrigger?.focus()); },
        trap(event) { const nodes = Array.from(this.$refs.drawer.querySelectorAll('a[href],button:not([disabled])')); if (!nodes.length) return; const first = nodes[0], last = nodes[nodes.length - 1]; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } }
     }"
     @keydown.escape.window="closeDrawer()">
    <x-account.portal-header :notification-count="$accountNotificationCount ?? 0" :profile-summary="$accountProfileSummary ?? null" />

    <div x-cloak x-show="drawerOpen" class="fixed inset-0 z-50 lg:hidden" role="presentation">
        <div class="absolute inset-0 bg-black/70" @click="closeDrawer()" aria-hidden="true"></div>
        <div id="account-navigation-drawer" x-ref="drawer" role="dialog" aria-modal="true" aria-label="Account navigation"
             @keydown.tab="trap($event)"
             class="absolute inset-y-0 left-0 w-[min(22rem,90vw)] overflow-hidden bg-slate-950 shadow-2xl motion-safe:transition-transform">
            <div class="flex min-h-16 items-center justify-between border-b border-white/[0.08] px-4">
                <p class="font-bold text-white">Account menu</p>
                <button x-ref="drawerClose" type="button" @click="closeDrawer()" class="inline-flex h-11 w-11 items-center justify-center rounded-xl hover:bg-white/[0.08] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400" aria-label="Close account navigation">✕</button>
            </div>
            <x-account.sidebar :menu="$accountMenu ?? []" drawer />
        </div>
    </div>

    <div class="mx-auto flex max-w-screen-2xl gap-6 px-4 py-6 sm:px-6 lg:py-8">
        @unless($accountFullWidth)
            <div class="sticky top-24 hidden h-fit lg:block"><x-account.sidebar :menu="$accountMenu ?? []" /></div>
        @endunless

        <main id="main-content" class="min-w-0 flex-1 pb-20 lg:pb-0">
            @hasSection('account-breadcrumbs')@yield('account-breadcrumbs')@endif
            <x-account.flash-messages />
            @yield('account-content')
        </main>
    </div>

    <x-account.portal-footer />

    <nav class="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 border-t border-white/[0.10] bg-slate-950/98 px-1 pb-[env(safe-area-inset-bottom)] lg:hidden" aria-label="Mobile account navigation" data-account-mobile-navigation>
        @foreach($mobilePrimaryItems as $item)
            <a href="{{ $item['url'] }}" @if(request()->routeIs($item['route'])) aria-current="page" @endif class="flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-[11px] font-semibold {{ request()->routeIs($item['route']) ? 'text-indigo-300' : 'text-slate-400' }} focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-400">
                <span aria-hidden="true">•</span><span class="truncate">{{ $item['mobile_label'] ?? $item['label'] }}</span>
            </a>
        @endforeach
        <button type="button" @click="openDrawer()" class="flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-[11px] font-semibold text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-400" aria-label="Open full account menu">
            <span aria-hidden="true">☰</span><span>Menu</span>
        </button>
    </nav>
</div>
@endsection
