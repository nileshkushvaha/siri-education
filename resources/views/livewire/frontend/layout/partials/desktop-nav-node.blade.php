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
            class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-bold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 {{ $node->isActive || $node->isAncestorActive ? 'bg-white text-indigo-700 shadow-sm ring-1 ring-indigo-100' : 'text-slate-600 hover:bg-white hover:text-slate-950 hover:shadow-sm' }}"
            aria-haspopup="true"
        >
            <span>{{ $node->label }}</span>
            @if($node->badgeText)<x-ui.badge color="indigo">{{ $node->badgeText }}</x-ui.badge>@endif
            <svg class="h-3.5 w-3.5 transition" x-bind:class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>

        <div
            x-show="open"
            x-transition
            x-cloak
            class="absolute left-1/2 top-full z-50 mt-3 w-[min(42rem,calc(100vw-2rem))] -translate-x-1/2 rounded-2xl border border-slate-200 bg-white p-3 shadow-2xl shadow-indigo-950/15"
        >
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach($node->children as $child)
                    @include('livewire.frontend.layout.partials.mega-menu-node', ['node' => $child])
                @endforeach
            </div>
        </div>
    @elseif(! $isEmpty)
        <a
            href="{{ $node->link->url }}"
            target="{{ $node->link->target }}"
            @if($node->link->rel) rel="{{ $node->link->rel }}" @endif
            class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-bold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 {{ $node->isActive ? 'bg-white text-indigo-700 shadow-sm ring-1 ring-indigo-100' : 'text-slate-600 hover:bg-white hover:text-slate-950 hover:shadow-sm' }}"
            @if($node->isActive) aria-current="page" @endif
            @if($node->link->isExternal()) aria-label="{{ $node->label }} (opens in new tab)" @endif
        >
            <span>{{ $node->label }}</span>
            @if($node->badgeText)<x-ui.badge color="indigo">{{ $node->badgeText }}</x-ui.badge>@endif
        </a>
    @else
        <span class="inline-flex px-3 py-2 text-sm font-semibold text-slate-500">{{ $node->label }}</span>
    @endif
</li>
