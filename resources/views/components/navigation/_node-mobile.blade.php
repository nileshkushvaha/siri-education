{{--
    Mobile navigation node — accordion with separate toggle button.
    Variables: $node (NavigationNode), $depth (int), $tree (NavigationTree)
--}}
@php
    $nodeId  = 'mnav-' . str_replace('-', '', $node->id);
    $hasKids = $node->hasChildren();
    $url     = $node->link->url;
    $indent  = $depth * 1.0;
@endphp

<li
    @if($hasKids)
        x-data="{ open: {{ $node->isAncestorActive || $node->isActive ? 'true' : 'false' }} }"
    @endif
    role="none"
>
    <div class="flex items-stretch">
        {{-- Primary link --}}
        @if(!$node->link->isEmpty())
        <a
            href="{{ $url }}"
            target="{{ $node->link->target }}"
            @if($node->link->rel) rel="{{ $node->link->rel }}" @endif
            style="padding-left: {{ 1 + $indent }}rem;"
            @class([
                'flex-1 flex items-center gap-2 py-3 pr-3 text-sm font-medium transition-colors',
                'text-white'                                        => $node->isActive,
                'text-white/70 '                                    => $node->isAncestorActive && !$node->isActive,
                'text-slate-300 hover:text-white'                   => !$node->isActive && !$node->isAncestorActive,
                $node->cssClass ?? '',
            ])
            @if($node->cssId) id="{{ $node->cssId }}" @endif
            aria-current="{{ $node->isActive ? 'page' : 'false' }}"
        >
            @if($node->icon)
                <span class="flex-shrink-0 text-base w-5 text-center leading-none" aria-hidden="true">{{ $node->icon }}</span>
            @endif
            <span class="flex-1">{{ $node->label }}</span>
            @if($node->badgeText)
                <span
                    class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium text-white"
                    style="background-color: {{ $node->badgeColor ?: '#6366f1' }}"
                >{{ $node->badgeText }}</span>
            @endif
        </a>
        @else
        <span
            style="padding-left: {{ 1 + $indent }}rem;"
            class="flex-1 flex items-center gap-2 py-3 pr-3 text-sm font-medium text-slate-400"
        >
            @if($node->icon)<span class="flex-shrink-0 text-base w-5 text-center leading-none" aria-hidden="true">{{ $node->icon }}</span>@endif
            <span class="flex-1">{{ $node->label }}</span>
        </span>
        @endif

        {{-- Toggle button for children --}}
        @if($hasKids)
        <button
            @click="open = !open"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-label="open ? 'Collapse {{ addslashes($node->label) }}' : 'Expand {{ addslashes($node->label) }}'"
            aria-controls="{{ $nodeId }}"
            class="px-4 flex items-center justify-center text-slate-400 hover:text-slate-200 transition-colors border-l border-white/[0.06]"
        >
            <svg
                class="h-4 w-4 transition-transform duration-200"
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
        class="border-l border-white/[0.06] ml-4"
        role="list"
        aria-label="{{ $node->label }} submenu"
    >
        @foreach($node->children as $child)
            @include('components.navigation._node-mobile', [
                'node'  => $child,
                'depth' => $depth + 1,
                'tree'  => $tree,
            ])
        @endforeach
    </ul>
    @endif
</li>
