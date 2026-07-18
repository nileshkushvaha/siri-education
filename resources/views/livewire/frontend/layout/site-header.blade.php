@php
    $headerNavigation = $this->headerNavigation;
    $mobileNavigation = $this->mobileNavigation;
    $dashboardUrl = $this->dashboardUrl();
@endphp

<header
    x-data="{ scrolled: false }"
    x-on:scroll.window="scrolled = window.scrollY > 12"
    class="sticky top-0 z-50 border-b border-slate-200/80 bg-white/90 text-slate-900 backdrop-blur-xl transition-all duration-300"
    x-bind:class="scrolled ? 'shadow-xl shadow-indigo-950/[0.07]' : 'shadow-sm shadow-slate-900/[0.03]'"
    data-public-site-header
>
    <div class="h-0.5 bg-gradient-to-r from-cyan-400 via-indigo-500 to-fuchsia-500" aria-hidden="true"></div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex min-h-[4.75rem] items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="group flex min-w-0 items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100">
                @if($logo)
                    <img src="{{ $logo }}" alt="{{ $appName }}" class="h-10 w-auto max-w-40 object-contain">
                @else
                    <span class="relative flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-500 via-violet-500 to-fuchsia-500 text-base font-black text-white shadow-lg shadow-indigo-200 transition group-hover:-rotate-3 group-hover:scale-105">
                        <span class="absolute inset-0 bg-gradient-to-tr from-white/0 to-white/25" aria-hidden="true"></span>
                        <span class="relative">{{ mb_substr($appName ?: config('app.name'), 0, 1) }}</span>
                    </span>
                @endif
                <span class="min-w-0">
                    <span class="block truncate text-base font-black tracking-tight text-slate-950">{{ $appName }}</span>
                    <span class="hidden text-[10px] font-bold uppercase tracking-[0.16em] text-indigo-500 sm:block">Learn · Teach · Grow</span>
                </span>
            </a>

            <nav class="hidden flex-1 items-center justify-center lg:flex" aria-label="Primary navigation">
                @if($headerNavigation && ! $headerNavigation->isEmpty())
                    <ul class="flex items-center gap-1 rounded-2xl border border-slate-200/80 bg-slate-50/80 p-1" role="list">
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
                    @if(Route::has('instructor.apply'))
                        <a href="{{ route('instructor.apply') }}" class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-bold text-slate-600 transition hover:bg-violet-50 hover:text-violet-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-100">Teach with us</a>
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
                    @if(Route::has('instructor.apply'))
                        <a href="{{ route('instructor.apply') }}" class="inline-flex min-h-12 items-center justify-center rounded-xl border border-violet-200 bg-violet-50 px-3 text-center text-sm font-bold text-violet-700">Teach with us</a>
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
