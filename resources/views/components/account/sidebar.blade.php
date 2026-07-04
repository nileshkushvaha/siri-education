@props(['menu' => []])

<aside class="hidden lg:flex flex-col w-56 flex-shrink-0" data-account-sidebar>
    <nav class="rounded-2xl border border-white/[0.07] p-3 sticky top-24" style="background:rgba(255,255,255,0.03)">

        {{-- User mini-card --}}
        <div class="flex items-center gap-2.5 px-3 py-2.5 mb-3 rounded-xl" style="background:rgba(255,255,255,0.04)">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center flex-shrink-0 overflow-hidden">
                @if(auth()->user()->avatar_url)
                    <img src="{{ auth()->user()->avatar_url }}" class="w-full h-full object-cover" alt="">
                @else
                    <span class="text-white font-bold text-xs">{{ strtoupper(substr(auth()->user()->first_name ?? auth()->user()->name, 0, 1)) }}</span>
                @endif
            </div>
            <div class="min-w-0">
                <p class="text-white text-xs font-semibold truncate">{{ auth()->user()->first_name ?? explode(' ', auth()->user()->name)[0] }}</p>
                <p class="text-slate-400 text-[10px] truncate">{{ auth()->user()->email }}</p>
            </div>
        </div>

        {{-- Nav items --}}
        @foreach($menu as $item)
            @php $active = request()->routeIs($item['route'] ?? ''); @endphp
            <a href="{{ $item['url'] }}"
               data-account-menu-item="{{ $item['route'] ?? '' }}"
               class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-all mb-0.5
                      {{ $active
                           ? 'bg-indigo-500/15 text-indigo-300 border border-indigo-500/25 account-menu-active'
                           : 'text-slate-400 hover:text-white hover:bg-white/[0.05]' }}">
                @if(($item['icon'] ?? '') === 'home')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'user')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'book')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'badge')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'bag')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'heart')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'star')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'bell')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'help')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'calendar')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'clipboard')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'credit-card')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'pencil')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'check-circle')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                @elseif(($item['icon'] ?? '') === 'chart-bar')
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                @else
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 6h16M4 12h16M4 18h7"/>
                    </svg>
                @endif
                <span class="flex-1 truncate">{{ $item['label'] }}</span>
                @if(!empty($item['badge']))
                    <span class="flex-shrink-0 min-w-[1.25rem] h-5 px-1.5 rounded-full bg-indigo-500/20 text-indigo-300 text-[10px] font-semibold flex items-center justify-center">
                        {{ $item['badge'] }}
                    </span>
                @endif
            </a>
            @if(!empty($item['children']))
                <div class="ml-6 mb-0.5 space-y-0.5">
                    @foreach($item['children'] as $child)
                        @php $childActive = request()->routeIs($child['route'] ?? ''); @endphp
                        <a href="{{ $child['url'] }}"
                           data-account-menu-item="{{ $child['route'] ?? '' }}"
                           class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-all
                                  {{ $childActive
                                       ? 'bg-indigo-500/15 text-indigo-300 account-menu-active'
                                       : 'text-slate-400 hover:text-white hover:bg-white/[0.05]' }}">
                            <span class="flex-1 truncate">{{ $child['label'] }}</span>
                            @if(!empty($child['badge']))
                                <span class="flex-shrink-0 min-w-[1.1rem] h-4 px-1 rounded-full bg-indigo-500/20 text-indigo-300 text-[9px] font-semibold flex items-center justify-center">
                                    {{ $child['badge'] }}
                                </span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        @endforeach

        {{-- Divider + Logout --}}
        <div class="mt-3 pt-3 border-t border-white/[0.06]">
            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button type="submit"
                        class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium text-slate-400 hover:text-rose-400 hover:bg-rose-500/[0.06] transition-all">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    Sign Out
                </button>
            </form>
        </div>
    </nav>
</aside>
