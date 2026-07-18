@props(['menu' => [], 'drawer' => false])

@php
    $container = $drawer
        ? 'h-full overflow-y-auto px-4 pb-6'
        : 'max-h-[calc(100vh-7rem)] overflow-y-auto rounded-2xl border border-white/[0.08] bg-white/[0.03] p-3';
@endphp

<aside class="{{ $drawer ? 'block' : 'hidden w-64 shrink-0 lg:block' }}" data-account-sidebar data-account-sidebar-mode="{{ $drawer ? 'drawer' : 'desktop' }}">
    <nav class="{{ $container }}" aria-label="Account navigation">
        @foreach($menu as $group)
            <section class="mb-5" aria-labelledby="account-nav-group-{{ Str::slug($group['label']) }}-{{ $drawer ? 'drawer' : 'desktop' }}">
                <h2 id="account-nav-group-{{ Str::slug($group['label']) }}-{{ $drawer ? 'drawer' : 'desktop' }}"
                    class="mb-1 px-3 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-500">
                    {{ $group['label'] }}
                </h2>
                <div class="space-y-1">
                    @foreach($group['items'] as $item)
                        @php $active = request()->routeIs($item['route']); @endphp
                        <a href="{{ $item['url'] }}"
                           data-account-menu-item="{{ $item['route'] }}"
                           @if($active) aria-current="page" @endif
                           class="{{ $active ? 'account-menu-active border-[rgb(var(--portal-a)/.28)] bg-gradient-to-r from-[rgb(var(--portal-a)/.16)] to-[rgb(var(--portal-c)/.07)] text-white' : 'border-transparent text-slate-300 hover:bg-white/[0.06] hover:text-white' }} flex min-h-11 items-center gap-3 rounded-xl border px-3 py-2 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center" aria-hidden="true">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 6h16M4 12h16M4 18h10"/></svg>
                            </span>
                            <span class="min-w-0 flex-1 break-words">{{ $item['label'] }}</span>
                            @if($item['badge'])
                                <span class="inline-flex min-h-6 min-w-6 shrink-0 items-center justify-center rounded-full bg-[rgb(var(--portal-c)/.14)] px-1.5 text-xs font-bold text-[rgb(var(--portal-c))]" aria-label="{{ $item['badge'] }} unread or pending">
                                    {{ $item['badge'] > 99 ? '99+' : $item['badge'] }}
                                </span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="border-t border-white/[0.08] pt-3">
            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button type="submit" class="flex min-h-11 w-full items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium text-slate-300 transition-colors hover:bg-rose-400/10 hover:text-rose-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-300">
                    <span aria-hidden="true">↪</span><span>Sign Out</span>
                </button>
            </form>
        </div>
    </nav>
</aside>
