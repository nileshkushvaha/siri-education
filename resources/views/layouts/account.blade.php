@extends('layouts.frontend')

@section('portal-shell', 'true')

@section('content')
@php
    $accountFullWidth = trim($__env->yieldContent('account-full-width')) === 'true';
    $accountTheme = ($accountAudience ?? null) === \App\Enums\PortalAudience::Instructor ? 'instructor' : 'student';
    $mobilePrimaryItems = collect($accountMenu ?? [])->flatMap(fn (array $group) => $group['items'])
        ->filter(fn (array $item) => $item['mobile_priority'] !== null)
        ->sortBy('mobile_priority');
@endphp

@push('head')
<style>
    [data-account-portal] {
        --portal-a: 34 211 238;
        --portal-b: 99 102 241;
        --portal-c: 217 70 239;
        --portal-d: 52 211 153;
        background:
            radial-gradient(circle at 12% 8%, rgb(var(--portal-a) / .075), transparent 25rem),
            radial-gradient(circle at 88% 30%, rgb(var(--portal-c) / .06), transparent 28rem),
            radial-gradient(circle at 55% 100%, rgb(var(--portal-b) / .05), transparent 32rem),
            var(--portal-surface);
    }
    [data-account-portal="instructor"] {
        --portal-a: 168 85 247;
        --portal-b: 99 102 241;
        --portal-c: 245 158 11;
        --portal-d: 52 211 153;
    }
    html:not(.dark) [data-account-portal] {
        --portal-a: 6 182 212;
        --portal-b: 99 102 241;
        --portal-c: 217 70 239;
        --portal-d: 16 185 129;
        background:
            radial-gradient(circle at 10% 6%, rgb(var(--portal-a) / .22), transparent 26rem),
            radial-gradient(circle at 90% 22%, rgb(var(--portal-c) / .18), transparent 30rem),
            radial-gradient(circle at 50% 100%, rgb(var(--portal-b) / .16), transparent 34rem),
            radial-gradient(circle at 25% 60%, rgb(var(--portal-d) / .10), transparent 24rem),
            var(--portal-surface);
    }
    html:not(.dark) [data-account-portal="instructor"] {
        --portal-a: 147 51 234;
        --portal-b: 79 70 229;
        --portal-c: 245 158 11;
        --portal-d: 16 185 129;
    }
    html:not(.dark) [data-account-card] {
        background:
            radial-gradient(circle at 0 0, rgb(var(--portal-a) / .16), transparent 15rem),
            radial-gradient(circle at 100% 100%, rgb(var(--portal-c) / .12), transparent 16rem),
            var(--portal-surface-raised);
        border-color: rgb(var(--portal-b) / .18);
        box-shadow: 0 12px 36px rgb(var(--portal-b) / .10);
    }
    html:not(.dark) [data-account-card]:hover {
        border-color: rgb(var(--portal-a) / .45);
        box-shadow: 0 18px 44px rgb(var(--portal-b) / .16);
    }
    html:not(.dark) [data-account-sidebar][data-account-sidebar-mode="desktop"] nav {
        border-color: rgb(var(--portal-b) / .18);
        background:
            radial-gradient(circle at 0 0, rgb(var(--portal-a) / .20), transparent 14rem),
            radial-gradient(circle at 100% 85%, rgb(var(--portal-c) / .14), transparent 15rem),
            linear-gradient(180deg, rgb(var(--portal-b) / .05), transparent),
            var(--portal-surface-raised);
        box-shadow: 0 12px 36px rgb(var(--portal-b) / .08);
    }
    html:not(.dark) [data-account-sidebar] h2 {
        color: rgb(var(--portal-b));
    }
    html:not(.dark) [data-account-sidebar] .account-menu-active {
        border-color: rgb(var(--portal-b) / .40);
        background: linear-gradient(90deg, rgb(var(--portal-b) / .18), rgb(var(--portal-a) / .16), rgb(var(--portal-c) / .12));
        color: rgb(var(--portal-b));
        box-shadow: 0 6px 18px rgb(var(--portal-b) / .14);
    }
    html:not(.dark) [data-account-sidebar] a:not(.account-menu-active):hover {
        background: rgb(var(--portal-b) / .08);
        color: rgb(var(--portal-b));
    }
    html:not(.dark) [data-account-header] {
        background:
            linear-gradient(90deg, rgb(var(--portal-a) / .12), rgb(var(--portal-b) / .08) 45%, rgb(var(--portal-c) / .12)),
            color-mix(in srgb, var(--portal-surface-solid) 92%, transparent);
        box-shadow: 0 6px 24px rgb(var(--portal-b) / .12);
    }
    html:not(.dark) #main-content > div:has(> h1),
    html:not(.dark) #main-content > div:has(> div:first-child > h1),
    html:not(.dark) #main-content > div > div:first-child:has(h1) {
        border-color: rgb(var(--portal-b) / .28);
        background:
            radial-gradient(circle at 92% 10%, rgb(var(--portal-c) / .30), transparent 13rem),
            radial-gradient(circle at 10% 100%, rgb(var(--portal-d) / .18), transparent 12rem),
            linear-gradient(120deg, rgb(var(--portal-a) / .24), rgb(var(--portal-b) / .16) 52%, var(--portal-surface-raised));
        box-shadow: 0 14px 40px rgb(var(--portal-b) / .14);
    }
    html:not(.dark) #main-content > div:has(> h1)::after,
    html:not(.dark) #main-content > div:has(> div:first-child > h1)::after,
    html:not(.dark) #main-content > div > div:first-child:has(h1)::after {
        background: rgb(var(--portal-c) / .28);
    }
    html:not(.dark) [data-account-mobile-navigation] {
        background:
            linear-gradient(90deg, rgb(var(--portal-a) / .14), rgb(var(--portal-b) / .10), rgb(var(--portal-c) / .14)),
            color-mix(in srgb, var(--portal-surface-solid) 96%, transparent);
        border-color: rgb(var(--portal-b) / .20);
    }
    [data-account-card] {
        background:
            radial-gradient(circle at 0 0, rgb(var(--portal-a) / .085), transparent 14rem),
            radial-gradient(circle at 100% 100%, rgb(var(--portal-c) / .055), transparent 15rem),
            var(--portal-surface-raised);
        box-shadow: inset 0 1px 0 var(--portal-glass-highlight), 0 18px 50px rgb(var(--portal-shadow) / .08);
    }
    [data-account-card]:hover {
        border-color: rgb(var(--portal-a) / .20);
        box-shadow: inset 0 1px 0 var(--portal-glass-highlight), 0 20px 55px rgb(var(--portal-b) / .10);
    }
    [data-account-sidebar][data-account-sidebar-mode="desktop"] nav {
        background:
            radial-gradient(circle at 0 0, rgb(var(--portal-a) / .10), transparent 13rem),
            radial-gradient(circle at 100% 80%, rgb(var(--portal-c) / .06), transparent 14rem),
            var(--portal-surface-raised);
    }
    #main-content > div:has(> h1),
    #main-content > div:has(> div:first-child > h1),
    #main-content > div > div:first-child:has(h1) {
        position: relative;
        overflow: hidden;
        padding: 1.25rem 1.375rem;
        border: 1px solid rgb(var(--portal-a) / .13);
        border-radius: 1.25rem;
        background:
            radial-gradient(circle at 92% 10%, rgb(var(--portal-c) / .12), transparent 12rem),
            linear-gradient(120deg, rgb(var(--portal-a) / .075), rgb(var(--portal-b) / .055) 52%, var(--portal-surface-raised));
        box-shadow: inset 0 1px 0 var(--portal-glass-highlight);
    }
    #main-content > div:has(> h1)::after,
    #main-content > div:has(> div:first-child > h1)::after,
    #main-content > div > div:first-child:has(h1)::after {
        content: '';
        position: absolute;
        inset: auto -2.5rem -4rem auto;
        width: 9rem;
        height: 9rem;
        border-radius: 9999px;
        background: rgb(var(--portal-a) / .08);
        filter: blur(12px);
        pointer-events: none;
    }
    #main-content > div:has(> h1) > h1,
    #main-content > div:has(> div:first-child > h1) > div:first-child > h1,
    #main-content > div > div:first-child:has(h1) h1 {
        position: relative;
        z-index: 1;
        margin-top: .375rem;
        margin-bottom: .375rem;
        background: linear-gradient(90deg, var(--portal-fg-strong), rgb(var(--portal-a)), rgb(var(--portal-c)));
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        font-size: clamp(1.75rem, 3vw, 2.25rem);
        font-weight: 700;
        line-height: 1.15;
        letter-spacing: -.025em;
    }
    #main-content > div:has(> h1) > p,
    #main-content > div:has(> div:first-child > h1) > div:first-child > p,
    #main-content > div > div:first-child:has(h1) p {
        position: relative;
        z-index: 1;
        max-width: 48rem;
        line-height: 1.6;
    }
    [data-account-mobile-navigation] {
        background:
            linear-gradient(90deg, rgb(var(--portal-a) / .055), rgb(var(--portal-b) / .04), rgb(var(--portal-c) / .05)),
            color-mix(in srgb, var(--portal-surface-solid) 96%, transparent);
        backdrop-filter: blur(18px);
    }
    @keyframes account-content-enter {
        from {
            opacity: 0;
            transform: translate3d(0, .75rem, 0);
        }
        to {
            opacity: 1;
            transform: translate3d(0, 0, 0);
        }
    }
    @keyframes account-header-glow {
        0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
        50% { transform: translate3d(-.65rem, -.35rem, 0) scale(1.08); }
    }
    [data-account-portal] #main-content > * {
        animation: account-content-enter .5s cubic-bezier(.22, 1, .36, 1) both;
    }
    [data-account-portal] #main-content > :nth-child(2) { animation-delay: 60ms; }
    [data-account-portal] #main-content > :nth-child(3) { animation-delay: 120ms; }
    #main-content > div:has(> h1)::after,
    #main-content > div:has(> div:first-child > h1)::after,
    #main-content > div > div:first-child:has(h1)::after {
        animation: account-header-glow 7s ease-in-out infinite;
    }
    @media (prefers-reduced-motion: reduce) {
        [data-account-portal] #main-content > *,
        #main-content > div:has(> h1)::after,
        #main-content > div:has(> div:first-child > h1)::after,
        #main-content > div > div:first-child:has(h1)::after {
            animation: none;
        }
    }
</style>
@endpush

<div class="min-h-screen text-fg" data-account-portal="{{ $accountTheme }}"
     x-data="{
        drawerOpen: false,
        openDrawer() { this.drawerOpen = true; document.body.style.overflow = 'hidden'; this.$nextTick(() => this.$refs.drawerClose?.focus()); },
        closeDrawer() { if (!this.drawerOpen) return; this.drawerOpen = false; document.body.style.overflow = ''; this.$nextTick(() => this.$refs.menuTrigger?.focus()); },
        trap(event) { const nodes = Array.from(this.$refs.drawer.querySelectorAll('a[href],button:not([disabled])')); if (!nodes.length) return; const first = nodes[0], last = nodes[nodes.length - 1]; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } }
     }"
     @keydown.escape.window="closeDrawer()">
    <x-account.portal-header
        :notification-count="$accountNotificationCount ?? 0"
        :profile-summary="$accountProfileSummary ?? null"
        :wallet-enabled="$accountWalletEnabled ?? false"
        :wallet-summary="$accountWalletSummary ?? null"
        :referral-enabled="$accountReferralEnabled ?? false"
        :booking-journey="$accountBookingJourney ?? null"
    />

    <div x-cloak x-show="drawerOpen" class="fixed inset-0 z-50 lg:hidden" role="presentation">
        <div class="absolute inset-0 bg-black/70" @click="closeDrawer()" aria-hidden="true"></div>
        <div id="account-navigation-drawer" x-ref="drawer" role="dialog" aria-modal="true" aria-label="Account navigation"
             @keydown.tab="trap($event)"
             class="absolute inset-y-0 left-0 w-[min(22rem,90vw)] overflow-hidden bg-surface-solid shadow-2xl ring-1 ring-edge motion-safe:transition-transform">
            <div class="flex min-h-16 items-center justify-between border-b border-edge px-4">
                <p class="font-bold text-fg-strong">Account menu</p>
                <button x-ref="drawerClose" type="button" @click="closeDrawer()" class="inline-flex h-11 w-11 items-center justify-center rounded-xl hover:bg-surface-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400" aria-label="Close account navigation">✕</button>
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

    <nav class="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 border-t border-edge px-1 pb-[env(safe-area-inset-bottom)] lg:hidden" aria-label="Mobile account navigation" data-account-mobile-navigation>
        @foreach($mobilePrimaryItems as $item)
            <a href="{{ $item['url'] }}" @if(request()->routeIs($item['route'])) aria-current="page" @endif class="flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-[11px] font-semibold {{ request()->routeIs($item['route']) ? 'text-[rgb(var(--portal-a))]' : 'text-fg-muted' }} focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-400">
                <span aria-hidden="true">•</span><span class="truncate">{{ $item['mobile_label'] ?? $item['label'] }}</span>
            </a>
        @endforeach
        <button type="button" @click="openDrawer()" class="flex min-h-14 flex-col items-center justify-center gap-1 px-1 text-[11px] font-semibold text-fg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-400" aria-label="Open full account menu">
            <span aria-hidden="true">☰</span><span>Menu</span>
        </button>
    </nav>
</div>
@endsection
