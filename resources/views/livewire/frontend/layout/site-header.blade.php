@php
    $headerNavigation = $this->headerNavigation;
    $mobileNavigation = $this->mobileNavigation;
    $dashboardUrl = $this->dashboardUrl();
    $topBar = $this->topBar();
@endphp

<header
    x-data="{ scrolled: false }"
    x-on:scroll.window="scrolled = window.scrollY > 12"
    class="sticky top-0 z-50 border-b border-slate-200/80 bg-white/90 text-slate-900 backdrop-blur-xl transition-all duration-300"
    x-bind:class="scrolled ? 'shadow-xl shadow-indigo-950/[0.07]' : 'shadow-sm shadow-slate-900/[0.03]'"
    data-public-site-header
>
    @if($topBar['enabled'])
        <div class="hidden bg-gradient-to-r from-slate-950 via-indigo-950 to-violet-950 text-indigo-50 md:block">
            <div class="mx-auto flex min-h-11 max-w-7xl items-center justify-between gap-6 px-6 lg:px-8">
                <div class="flex items-center gap-6 text-xs font-bold">
                    @if($topBar['phone'])
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $topBar['phone']) }}" class="inline-flex items-center gap-2 transition hover:text-cyan-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-300">
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/10 text-fuchsia-300" aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.362-.271.527-.734.417-1.173L6.963 3.102A1.125 1.125 0 0 0 5.872 2.25H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/></svg>
                            </span>
                            {{ $topBar['phone'] }}
                        </a>
                    @endif
                    @if($topBar['email'])
                        <a href="mailto:{{ $topBar['email'] }}" class="inline-flex items-center gap-2 transition hover:text-cyan-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-300">
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/10 text-cyan-300" aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21.75 6.75v10.5A2.25 2.25 0 0 1 19.5 19.5h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0-8.69 5.514a2 2 0 0 1-2.12 0L2.25 6.75"/></svg>
                            </span>
                            {{ $topBar['email'] }}
                        </a>
                    @endif
                </div>

                @if($topBar['socialLinks'])
                    <div class="flex items-center gap-4">
                        <span class="text-[10px] font-black uppercase tracking-[0.2em] text-indigo-300">Follow us</span>
                        @foreach($topBar['socialLinks'] as $network => $url)
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="flex h-7 w-7 items-center justify-center rounded-lg text-indigo-200 transition hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-300" aria-label="{{ ucfirst($network) }} (opens in new tab)">
                                @switch($network)
                                    @case('facebook')
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 22v-9h3l.5-3.5h-3.5V7.25c0-1.01.28-1.7 1.75-1.7H17V2.42A23.4 23.4 0 0 0 14.44 2C11.9 2 10 3.55 10 6.4v3.1H7V13h3v9h3.5Z"/></svg>
                                        @break
                                    @case('instagram')
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5" stroke-width="2"/><circle cx="12" cy="12" r="4" stroke-width="2"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>
                                        @break
                                    @case('x')
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.9 2H22l-6.77 7.74L23.2 22h-6.24l-4.89-6.39L6.48 22H3.36l7.26-8.3L2.98 2h6.4l4.42 5.84L18.9 2Zm-1.1 17.84h1.73L8.44 4.05H6.58L17.8 19.84Z"/></svg>
                                        @break
                                    @case('youtube')
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.12C19.54 3.58 12 3.58 12 3.58s-7.54 0-9.4.5A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.12c1.86.5 9.4.5 9.4.5s7.54 0 9.4-.5a3 3 0 0 0 2.1-2.12A31 31 0 0 0 24 12a31 31 0 0 0-.5-5.8ZM9.6 15.6V8.4L15.84 12 9.6 15.6Z"/></svg>
                                        @break
                                @endswitch
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="h-0.5 bg-gradient-to-r from-cyan-400 via-indigo-500 to-fuchsia-500" aria-hidden="true"></div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex min-h-[5.5rem] items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="group relative flex min-w-0 shrink-0 items-center rounded-2xl px-3.5 py-2.5 transition duration-300 hover:-translate-y-0.5 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200 sm:px-4 sm:py-3">
                <span class="pointer-events-none absolute inset-0 rounded-2xl bg-gradient-to-br from-cyan-100/40 via-transparent to-fuchsia-100/40 opacity-0 transition duration-300 group-hover:opacity-100" aria-hidden="true"></span>
                @if($logo)
                    <img src="{{ $logo }}" alt="{{ $appName }}" class="relative h-12 w-auto max-w-48 object-contain sm:h-14">
                @else
                    <x-ui.brand-logo variant="light" :label="$appName" class="relative block h-12 w-auto transition duration-300 group-hover:scale-[1.02] sm:h-14" />
                @endif
            </a>

            <nav class="hidden flex-1 items-center justify-center lg:flex" aria-label="Primary navigation">
                @if($headerNavigation && ! $headerNavigation->isEmpty())
                    <ul class="flex items-center gap-7" role="list">
                        @foreach($headerNavigation->nodes as $node)
                            @include('livewire.frontend.layout.partials.desktop-nav-node', ['node' => $node])
                        @endforeach
                    </ul>
                @endif
            </nav>

            <div class="flex items-center gap-1.5 sm:gap-2">
                <button
                    type="button"
                    wire:click="openSearch"
                    class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100"
                    aria-label="Open search"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.2-5.2m0 0A7.5 7.5 0 105.2 5.2a7.5 7.5 0 0010.6 10.6z"/></svg>
                </button>

                <div class="hidden items-center gap-1.5 lg:flex">
                    @if(Route::has('booking.create'))
                        <a href="{{ route('booking.create') }}" class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-bold text-slate-600 transition hover:bg-violet-50 hover:text-violet-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-100">Book a Demo Class</a>
                    @endif
                    @auth
                        @if($dashboardUrl)
                            <a href="{{ $dashboardUrl }}" class="inline-flex min-h-11 items-center rounded-xl border border-indigo-200 bg-indigo-50 px-4 text-sm font-bold text-indigo-700 transition hover:border-indigo-300 hover:bg-indigo-100 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100">Dashboard</a>
                        @endif
                        <form method="POST" action="{{ route('auth.logout') }}">
                            @csrf
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-bold text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-4 focus-visible:ring-slate-200">Sign out</button>
                        </form>
                    @else
                        @if(Route::has('auth.login'))
                            <a href="{{ route('auth.login') }}" class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-bold text-slate-600 transition hover:bg-slate-100 hover:text-slate-950 focus:outline-none focus-visible:ring-4 focus-visible:ring-slate-200">Sign in</a>
                        @endif
                        @if(Route::has('auth.register'))
                            <a href="{{ route('auth.register') }}" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-600 px-4 text-sm font-bold text-white shadow-lg shadow-indigo-200 transition hover:-translate-y-0.5 hover:shadow-xl hover:shadow-violet-200 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-200">
                                Get started
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6"/></svg>
                            </a>
                        @endif
                    @endauth
                </div>

                <button
                    type="button"
                    wire:click="toggleMobile"
                    class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 lg:hidden"
                    aria-controls="public-mobile-navigation"
                    aria-expanded="{{ $mobileOpen ? 'true' : 'false' }}"
                    aria-label="Toggle navigation"
                >
                    @if($mobileOpen)
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    @else
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
                    @endif
                </button>
            </div>
        </div>
    </div>

    @if($mobileOpen)
        <div id="public-mobile-navigation" class="border-t border-slate-200 bg-white/98 shadow-2xl shadow-slate-950/10 lg:hidden">
            <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6">
                <div class="mb-4 rounded-2xl bg-gradient-to-r from-indigo-50 via-violet-50 to-fuchsia-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-[0.15em] text-indigo-600">Explore {{ $appName }}</p>
                    <p class="mt-1 text-sm text-slate-600">Find instructors, discover learning resources, or start teaching.</p>
                </div>

                @if($mobileNavigation && ! $mobileNavigation->isEmpty())
                    <nav aria-label="Mobile navigation">
                        <ul class="space-y-1" role="list">
                            @foreach($mobileNavigation->nodes as $node)
                                @include('livewire.frontend.layout.partials.mobile-nav-node', ['node' => $node, 'depth' => 0])
                            @endforeach
                        </ul>
                    </nav>
                @endif

                <div class="mt-5 grid grid-cols-2 gap-2 border-t border-slate-200 pt-5">
                    @if(Route::has('booking.create'))
                        <a href="{{ route('booking.create') }}" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-3 text-center text-sm font-bold text-violet-700">Book a Demo Class</a>
                    @endif
                    @auth
                        @if($dashboardUrl)
                            <a href="{{ $dashboardUrl }}" class="inline-flex min-h-12 items-center justify-center rounded-xl bg-indigo-600 px-3 text-center text-sm font-bold text-white">Dashboard</a>
                        @endif
                        <form method="POST" action="{{ route('auth.logout') }}" class="col-span-2">
                            @csrf
                            <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-slate-100 px-3 text-sm font-bold text-slate-700">Sign out</button>
                        </form>
                    @else
                        @if(Route::has('auth.login'))
                            <a href="{{ route('auth.login') }}" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 text-center text-sm font-bold text-slate-700">Sign in</a>
                        @endif
                        @if(Route::has('auth.register'))
                            <a href="{{ route('auth.register') }}" class="col-span-2 inline-flex min-h-12 items-center justify-center rounded-xl bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-600 px-4 text-sm font-bold text-white shadow-lg shadow-indigo-200">Create your free account</a>
                        @endif
                    @endauth
                </div>
            </div>
        </div>
    @endif
</header>
