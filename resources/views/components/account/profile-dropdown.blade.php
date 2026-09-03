@inject('resolver', 'App\Services\PortalResolver')

@auth
@php
    $user = auth()->user();
    $menu = $resolver->profileMenu($user);
    $initials = strtoupper(substr($user->first_name ?? $user->name, 0, 1));
    $displayName = $user->first_name
        ? $user->first_name . ' ' . ($user->last_name ?? '')
        : $user->name;
    $role = $user->getRoleNames()->first() ?? 'Member';
    $rolePill = str_replace('_', ' ', ucfirst($role));
@endphp

<div x-data="{ open: false }" @keydown.escape.window="open = false" class="relative">

    {{-- Trigger button --}}
    <button
        @click="open = !open"
        :aria-expanded="open"
        class="flex items-center gap-2 px-2 py-1.5 rounded-xl border border-transparent hover:border-violet-200 hover:bg-violet-50/80 transition-all duration-200 group"
    >
        {{-- Avatar --}}
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center flex-shrink-0 overflow-hidden shadow-sm">
            @if($user->avatar_thumb_url)
                <img src="{{ $user->avatar_thumb_url }}" class="w-full h-full object-cover" alt="{{ $displayName }}">
            @else
                <span class="text-fg-strong font-bold text-xs">{{ $initials }}</span>
            @endif
        </div>

        {{-- Name (desktop only) --}}
        <span class="hidden xl:block text-sm font-semibold text-slate-700 group-hover:text-violet-700 transition-colors max-w-[120px] truncate">
            {{ $displayName }}
        </span>

        {{-- Chevron --}}
        <svg class="hidden xl:block w-3.5 h-3.5 text-fg-muted transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
    </button>

    {{-- Dropdown panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        @click.outside="open = false"
        x-cloak
        class="absolute right-0 top-full mt-2 w-64 rounded-2xl shadow-xl shadow-violet-100/60 overflow-hidden z-50"
        style="background: rgba(255,255,255,0.96); backdrop-filter: blur(24px); border: 1px solid rgba(139,92,246,0.15);"
    >
        {{-- User info header --}}
        <div class="px-4 py-3.5 border-b border-violet-100/80">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center flex-shrink-0 overflow-hidden">
                    @if($user->avatar_thumb_url)
                        <img src="{{ $user->avatar_thumb_url }}" class="w-full h-full object-cover" alt="">
                    @else
                        <span class="text-fg-strong font-bold text-sm">{{ $initials }}</span>
                    @endif
                </div>
                <div class="min-w-0">
                    <p class="text-slate-800 font-semibold text-sm truncate">{{ $displayName }}</p>
                    <p class="text-fg-muted text-xs truncate">{{ $user->email }}</p>
                    <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-[10px] font-semibold text-violet-700 bg-violet-100">
                        {{ $rolePill }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Menu items --}}
        <div class="py-1.5">
            @foreach($menu as $item)
                @if($item['divider'] ?? false)
                    <div class="my-1.5 border-t border-violet-100/80"></div>
                @endif
                <a href="{{ $item['url'] }}"
                   @if($item['external'] ?? false) target="_blank" rel="noopener" @endif
                   class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-600 hover:text-violet-700 hover:bg-violet-50/80 transition-all">
                    @if(($item['icon'] ?? '') === 'cog')
                        <svg class="w-4 h-4 text-fg-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    @elseif(($item['icon'] ?? '') === 'home')
                        <svg class="w-4 h-4 text-fg-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                    @elseif(($item['icon'] ?? '') === 'user')
                        <svg class="w-4 h-4 text-fg-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    @elseif(($item['icon'] ?? '') === 'shield')
                        <svg class="w-4 h-4 text-fg-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    @endif
                    <span>{{ $item['label'] }}</span>
                    @if($item['external'] ?? false)
                        <svg class="w-3 h-3 text-fg-muted ml-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    @endif
                </a>
            @endforeach
        </div>

        {{-- Sign out --}}
        <div class="px-3 pb-3 pt-1.5 border-t border-violet-100/80">
            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button type="submit"
                        class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm text-fg-faint hover:text-rose-600 hover:bg-rose-50 transition-all">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    Sign out
                </button>
            </form>
        </div>
    </div>
</div>
@endauth
