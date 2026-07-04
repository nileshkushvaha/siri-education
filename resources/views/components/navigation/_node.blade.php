{{--
    Shared recursive node for standard (horizontal) navigation.
    Variables: $node (NavigationNode), $depth (int), $tree (NavigationTree)
--}}
@php
    $nodeId  = 'nav-dd-' . str_replace('-', '', $node->id);
    $isRoot  = $depth === 0;
    $hasKids = $node->hasChildren();
    $url     = $node->link->url;
    $isEmpty = $node->link->isEmpty();
@endphp

<li
    @if($hasKids && $isRoot)
        x-data="{ open: false }"
        @mouseenter="open = true"
        @mouseleave="open = false"
        @focusout="
            if (!$el.contains($event.relatedTarget)) { open = false }
        "
    @endif
    class="{{ $isRoot ? 'relative' : '' }}"
    role="none"
>
    @if($isEmpty || $hasKids)
    {{-- Root item that is a toggle (no link or has children) --}}
    <button
        @if($hasKids && $isRoot)
            @click="open = !open"
            :aria-expanded="open ? 'true' : 'false'"
            aria-controls="{{ $nodeId }}"
            aria-haspopup="true"
        @endif
        @if(!$isEmpty && !$hasKids)
            onclick="window.location='{{ $url }}'"
        @endif
        @class([
            'flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all duration-200',
            'text-violet-700 bg-violet-50 font-semibold'  => $node->isActive,
            'text-slate-400 hover:text-violet-700 hover:bg-violet-50' => ! $node->isActive,
            $node->cssClass ?? '',
        ])
        @if($node->cssId) id="{{ $node->cssId }}" @endif
        @foreach($node->link->attributes as $attr => $val)
            {{ $attr }}="{{ $val }}"
        @endforeach
        aria-current="{{ $node->isActive ? 'page' : 'false' }}"
    >
        @if($node->icon)
            <span class="flex-shrink-0 text-base leading-none" aria-hidden="true">{{ $node->icon }}</span>
        @endif
        <span>{{ $node->label }}</span>
        @if($node->badgeText)
            <span
                class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium text-white ml-1"
                style="background-color: {{ $node->badgeColor ?: '#6366f1' }}"
                aria-label="{{ $node->label }} — {{ $node->badgeText }}"
            >{{ $node->badgeText }}</span>
        @endif
        @if($hasKids && $isRoot)
            <svg
                class="h-3.5 w-3.5 flex-shrink-0 transition-transform duration-200"
                :class="{ 'rotate-180': open }"
                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        @endif
    </button>
    @else
    {{-- Regular link --}}
    <a
        href="{{ $url }}"
        target="{{ $node->link->target }}"
        @if($node->link->rel) rel="{{ $node->link->rel }}" @endif
        @class([
            'flex items-center gap-1.5 transition-all duration-200',
            'px-3 py-2 rounded-lg text-sm font-medium' => $isRoot,
            'px-3 py-2 text-sm'                        => !$isRoot,
            'text-violet-700 bg-violet-50 font-semibold'            => $node->isActive && $isRoot,
            'text-violet-600 font-medium'                            => $node->isActive && !$isRoot,
            'text-slate-400 hover:text-violet-700 hover:bg-violet-50' => !$node->isActive && $isRoot,
            'text-slate-400 hover:text-violet-600 hover:bg-violet-50/60 rounded-md' => !$node->isActive && !$isRoot,
            $node->cssClass ?? '',
        ])
        @if($node->cssId) id="{{ $node->cssId }}" @endif
        @foreach($node->link->attributes as $attr => $val)
            {{ $attr }}="{{ $val }}"
        @endforeach
        aria-current="{{ $node->isActive ? 'page' : 'false' }}"
        @if($node->openInModal) data-modal-trigger @endif
        @if($node->link->isExternal()) aria-label="{{ $node->label }} (opens in new tab)" @endif
    >
        @if($node->icon)
            <span class="flex-shrink-0 text-base leading-none" aria-hidden="true">{{ $node->icon }}</span>
        @endif
        <span>{{ $node->label }}</span>
        @if($node->badgeText)
            <span
                class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium text-white ml-1"
                style="background-color: {{ $node->badgeColor ?: '#6366f1' }}"
            >{{ $node->badgeText }}</span>
        @endif
        @if($node->link->isExternal())
            <svg class="h-3 w-3 flex-shrink-0 ml-0.5 opacity-50" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
            </svg>
        @endif
    </a>
    @endif

    {{-- Dropdown for root items with children --}}
    @if($hasKids && $isRoot)
        <ul
            id="{{ $nodeId }}"
            x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95 translate-y-1"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute top-full left-0 mt-2 min-w-[14rem] py-2 rounded-2xl border border-violet-100 shadow-2xl shadow-violet-100/60 z-50 focus:outline-none"
            style="display:none; background:rgba(255,255,255,0.97); backdrop-filter:blur(24px);"
            role="menu"
            aria-label="{{ $node->label }} submenu"
        >
            @foreach($node->children as $child)
                @include('components.navigation._node', [
                    'node'  => $child,
                    'depth' => $depth + 1,
                    'tree'  => $tree,
                ])
            @endforeach
        </ul>
    @elseif($hasKids && !$isRoot)
        {{-- Nested dropdown (submenu within a dropdown) --}}
        <ul
            class="pl-3 py-1 border-l border-white/[0.06] ml-3 mt-0.5 space-y-0.5"
            role="group"
            aria-label="{{ $node->label }} submenu"
        >
            @foreach($node->children as $child)
                @include('components.navigation._node', [
                    'node'  => $child,
                    'depth' => $depth + 1,
                    'tree'  => $tree,
                ])
            @endforeach
        </ul>
    @endif
</li>
