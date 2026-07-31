@php
    $hasChildren = $node->hasChildren();
    $isEmpty = $node->link->isEmpty();
@endphp

<li class="relative" @if($hasChildren) x-data="{ open: false }" x-on:mouseenter="open = true" x-on:mouseleave="open = false" x-on:focusout="if (!$el.contains($event.relatedTarget)) open = false" @endif>
    @if($hasChildren)
        <button
            type="button"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open"
            class="relative inline-flex items-center gap-1.5 py-2 text-sm font-bold transition after:absolute after:inset-x-0 after:-bottom-0.5 after:h-0.5 after:origin-center after:rounded-full after:bg-gradient-to-r after:from-cyan-400 after:via-indigo-500 after:to-fuchsia-500 after:transition-transform focus:outline-none focus-visible:rounded focus-visible:ring-4 focus-visible:ring-indigo-100 {{ $node->isActive || $node->isAncestorActive ? 'text-indigo-700 after:scale-x-100' : 'text-slate-600 after:scale-x-0 hover:text-slate-950 hover:after:scale-x-100' }}"
            aria-haspopup="true"
        >
            @if($node->icon)
                @if(str_starts_with($node->icon, 'heroicon-'))
                    <x-filament::icon :icon="$node->icon" class="h-5 w-5 shrink-0 text-slate-500" />
                @else
                    <span class="{{ $node->icon }} h-5 w-5 shrink-0 text-slate-500" aria-hidden="true"></span>
                @endif
            @endif
            <span>{{ $node->label }}</span>
            @if($node->badgeText)<x-ui.badge color="indigo">{{ $node->badgeText }}</x-ui.badge>@endif
            <svg class="h-3.5 w-3.5 transition" x-bind:class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>

        <div
            x-show="open"
            x-transition
            x-cloak
            class="absolute left-1/2 top-full z-50 w-[min(42rem,calc(100vw-2rem))] -translate-x-1/2 pt-3"
        >
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-indigo-950/15">
                <div class="h-1 bg-gradient-to-r from-cyan-400 via-indigo-500 to-fuchsia-500" aria-hidden="true"></div>
                <div class="grid gap-2 p-3 sm:grid-cols-2">
                    @foreach($node->children as $child)
                        @include('livewire.frontend.layout.partials.mega-menu-node', ['node' => $child])
                    @endforeach
                </div>
            </div>
        </div>
    @elseif(! $isEmpty)
        <a
            href="{{ $node->link->url }}"
            target="{{ $node->link->target }}"
            @if($node->link->rel) rel="{{ $node->link->rel }}" @endif
            class="relative inline-flex items-center gap-1.5 py-2 text-sm font-bold transition after:absolute after:inset-x-0 after:-bottom-0.5 after:h-0.5 after:origin-center after:rounded-full after:bg-gradient-to-r after:from-cyan-400 after:via-indigo-500 after:to-fuchsia-500 after:transition-transform focus:outline-none focus-visible:rounded focus-visible:ring-4 focus-visible:ring-indigo-100 {{ $node->isActive ? 'text-indigo-700 after:scale-x-100' : 'text-slate-600 after:scale-x-0 hover:text-slate-950 hover:after:scale-x-100' }}"
            @if($node->isActive) aria-current="page" @endif
            @if($node->link->isExternal()) aria-label="{{ $node->label }} (opens in new tab)" @endif
        >
            @if($node->icon)
                @if(str_starts_with($node->icon, 'heroicon-'))
                    <x-filament::icon :icon="$node->icon" class="h-5 w-5 shrink-0 text-slate-500" />
                @else
                    <span class="{{ $node->icon }} h-5 w-5 shrink-0 text-slate-500" aria-hidden="true"></span>
                @endif
            @endif
            <span>{{ $node->label }}</span>
            @if($node->badgeText)<x-ui.badge color="indigo">{{ $node->badgeText }}</x-ui.badge>@endif
        </a>
    @else
        <span class="inline-flex px-3 py-2 text-sm font-semibold text-slate-500">{{ $node->label }}</span>
    @endif
</li>
