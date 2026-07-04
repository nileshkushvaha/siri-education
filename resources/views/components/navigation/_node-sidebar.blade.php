{{--
    Recursive sidebar/accordion node.
    Variables: $node (NavigationNode), $depth (int), $tree (NavigationTree)
--}}
@php
    $nodeId  = 'snav-' . str_replace('-', '', $node->id);
    $hasKids = $node->hasChildren();
    $url     = $node->link->url;
    $isEmpty = $node->link->isEmpty();
    $indent  = $depth * 0.75;
@endphp

<li
    @if($hasKids)
        x-data="{ open: {{ $node->isAncestorActive || $node->isActive ? 'true' : 'false' }} }"
    @endif
    class="space-y-0.5"
    role="none"
>
    <div class="flex items-stretch gap-0.5">
        {{-- Main link or button --}}
        @if($isEmpty && $hasKids)
        <button
            @if($hasKids)
                @click="open = !open"
                :aria-expanded="open ? 'true' : 'false'"
                aria-controls="{{ $nodeId }}"
            @endif
            style="padding-left: {{ 0.75 + $indent }}rem;"
            @class([
                'flex-1 flex items-center gap-2 py-2 pr-3 rounded-lg text-sm font-medium text-left transition-colors',
                'text-white bg-white/[0.07]'                   => $node->isActive,
                'text-white/80 bg-white/[0.04]'                => $node->isAncestorActive && !$node->isActive,
                'text-slate-400 hover:text-white hover:bg-white/[0.04]' => !$node->isActive && !$node->isAncestorActive,
                $node->cssClass ?? '',
            ])
            @if($node->cssId) id="{{ $node->cssId }}" @endif
            aria-current="{{ $node->isActive ? 'page' : 'false' }}"
        >
            @if($node->icon)
                <span class="flex-shrink-0 text-base w-5 text-center leading-none" aria-hidden="true">{{ $node->icon }}</span>
            @endif
            <span class="flex-1 truncate">{{ $node->label }}</span>
            @if($node->badgeText)
                <span
                    class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium text-white"
                    style="background-color: {{ $node->badgeColor ?: '#6366f1' }}"
                >{{ $node->badgeText }}</span>
            @endif
        </button>
        @else
        <a
            href="{{ $url }}"
            target="{{ $node->link->target }}"
            @if($node->link->rel) rel="{{ $node->link->rel }}" @endif
            style="padding-left: {{ 0.75 + $indent }}rem;"
            @class([
                'flex-1 flex items-center gap-2 py-2 pr-3 rounded-lg text-sm font-medium transition-colors',
                'text-white bg-white/[0.07]'                   => $node->isActive,
                'text-white/80 bg-white/[0.04]'                => $node->isAncestorActive && !$node->isActive,
                'text-slate-400 hover:text-white hover:bg-white/[0.04]' => !$node->isActive && !$node->isAncestorActive,
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
                <span class="flex-shrink-0 text-base w-5 text-center leading-none" aria-hidden="true">{{ $node->icon }}</span>
            @endif
            <span class="flex-1 truncate">{{ $node->label }}</span>
            @if($node->badgeText)
                <span
                    class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium text-white"
                    style="background-color: {{ $node->badgeColor ?: '#6366f1' }}"
                >{{ $node->badgeText }}</span>
            @endif
        </a>
        @endif

        {{-- Expand/collapse button for items with children --}}
        @if($hasKids)
        <button
            @click="open = !open"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-label="open ? 'Collapse {{ addslashes($node->label) }}' : 'Expand {{ addslashes($node->label) }}'"
            aria-controls="{{ $nodeId }}"
            class="flex-shrink-0 w-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-300 hover:bg-white/[0.04] transition-colors"
        >
            <svg
                class="h-3.5 w-3.5 transition-transform duration-200"
                :class="{ 'rotate-90': open }"
                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </button>
        @endif
    </div>

    {{-- Children --}}
    @if($hasKids)
    <ul
        id="{{ $nodeId }}"
        x-show="open"
        x-collapse
        class="space-y-0.5"
        role="list"
        aria-label="{{ $node->label }} submenu"
    >
        @foreach($node->children as $child)
            @include('components.navigation._node-sidebar', [
                'node'  => $child,
                'depth' => $depth + 1,
                'tree'  => $tree,
            ])
        @endforeach
    </ul>
    @endif
</li>
