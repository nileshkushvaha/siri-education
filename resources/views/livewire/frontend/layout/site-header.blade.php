@php
    $headerNavigation = $this->headerNavigation;
    $mobileNavigation = $this->mobileNavigation;
    $dashboardUrl = $this->dashboardUrl();
@endphp

<header
    x-data="{ scrolled: false }"
    x-on:scroll.window="scrolled = window.scrollY > 12"
    class="sticky top-0 z-50 border-b border-slate-200/70 bg-white/90 backdrop-blur-xl transition-shadow dark:border-white/10 dark:bg-slate-950/88"
    x-bind:class="scrolled ? 'shadow-lg shadow-slate-900/5 dark:shadow-black/30' : ''"
>
    <div class="h-0.5 bg-gradient-to-r from-indigo-500 via-violet-500 to-emerald-400" aria-hidden="true"></div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex min-h-18 items-center justify-between gap-4 py-3">
            <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3 rounded-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:focus-visible:ring-indigo-400/20">
                @if($logo)
                    <img src="{{ $logo }}" alt="{{ $appName }}" class="h-10 w-auto max-w-40 object-contain">
                @else
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-600 to-violet-600 text-base font-black text-white shadow-lg shadow-indigo-600/20">
                        {{ mb_substr($appName ?: config('app.name'), 0, 1) }}
                    </span>
                @endif
                <span class="truncate text-base font-extrabold tracking-tight text-slate-950 dark:text-white">{{ $appName }}</span>
            </a>

            <nav class="hidden flex-1 items-center justify-center lg:flex" aria-label="Primary navigation">
                @if($headerNavigation && ! $headerNavigation->isEmpty())
                    <ul class="flex items-center gap-1" role="list">
                        @foreach($headerNavigation->nodes as $node)
                            @include('livewire.frontend.layout.partials.desktop-nav-node', ['node' => $node])
                        @endforeach
                    </ul>
                @endif
            </nav>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    wire:click="openSearch"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-indigo-600 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-indigo-200 dark:focus-visible:ring-indigo-400/20"
                    aria-label="Open search"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.2-5.2m0 0A7.5 7.5 0 105.2 5.2a7.5 7.5 0 0010.6 10.6z"/></svg>
                </button>

                <div class="hidden items-center gap-2 lg:flex">
                    @auth
                        @if($dashboardUrl)
                            <x-ui.button href="{{ $dashboardUrl }}" variant="secondary" size="sm">Dashboard</x-ui.button>
                        @endif
                        <form method="POST" action="{{ route('auth.logout') }}">
                            @csrf
                            <x-ui.button type="submit" variant="ghost" size="sm">Sign out</x-ui.button>
                        </form>
                    @else
                        @if(Route::has('auth.login'))
                            <x-ui.button href="{{ route('auth.login') }}" variant="ghost" size="sm">Sign in</x-ui.button>
                        @endif
                        @if(Route::has('auth.register'))
                            <x-ui.button href="{{ route('auth.register') }}" size="sm">Get started</x-ui.button>
                        @endif
                    @endauth
                </div>

                <button
                    type="button"
                    wire:click="toggleMobile"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-600 transition hover:bg-slate-100 hover:text-indigo-600 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10 dark:hover:text-indigo-200 dark:focus-visible:ring-indigo-400/20 lg:hidden"
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
        <div id="public-mobile-navigation" class="border-t border-slate-200 bg-white dark:border-white/10 dark:bg-slate-950 lg:hidden">
            <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6">
                @if($mobileNavigation && ! $mobileNavigation->isEmpty())
                    <nav aria-label="Mobile navigation">
                        <ul class="space-y-1" role="list">
                            @foreach($mobileNavigation->nodes as $node)
                                @include('livewire.frontend.layout.partials.mobile-nav-node', ['node' => $node, 'depth' => 0])
                            @endforeach
                        </ul>
                    </nav>
                @endif

                <div class="mt-4 grid gap-2 border-t border-slate-200 pt-4 dark:border-white/10">
                    @auth
                        @if($dashboardUrl)
                            <x-ui.button href="{{ $dashboardUrl }}" variant="secondary">Dashboard</x-ui.button>
                        @endif
                        <form method="POST" action="{{ route('auth.logout') }}">
                            @csrf
                            <x-ui.button type="submit" variant="ghost" class="w-full">Sign out</x-ui.button>
                        </form>
                    @else
                        @if(Route::has('auth.login'))
                            <x-ui.button href="{{ route('auth.login') }}" variant="secondary">Sign in</x-ui.button>
                        @endif
                        @if(Route::has('auth.register'))
                            <x-ui.button href="{{ route('auth.register') }}">Get started</x-ui.button>
                        @endif
                    @endauth
                </div>
            </div>
        </div>
    @endif
</header>
