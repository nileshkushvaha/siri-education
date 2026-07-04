{{--
    Footer navigation node (flat list item, no deep nesting rendered).
    Variables: $node (NavigationNode)
--}}
@php
    $url = $node->link->url;
@endphp
<li role="none">
    @if(!$node->link->isEmpty())
    <a
        href="{{ $url }}"
        target="{{ $node->link->target }}"
        @if($node->link->rel) rel="{{ $node->link->rel }}" @endif
        @if($node->cssId) id="{{ $node->cssId }}" @endif
        @class([
            'inline-flex items-center gap-1.5 text-sm transition-colors py-1',
            'text-slate-300 font-medium' => $node->isActive,
            'text-slate-400 hover:text-slate-300' => !$node->isActive,
            $node->cssClass ?? '',
        ])
        aria-current="{{ $node->isActive ? 'page' : 'false' }}"
        @if($node->link->isExternal()) aria-label="{{ $node->label }} (opens in new tab)" @endif
    >
        @if($node->icon)
            <span class="text-sm leading-none" aria-hidden="true">{{ $node->icon }}</span>
        @endif
        {{ $node->label }}
        @if($node->badgeText)
            <span
                class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium text-white"
                style="background-color: {{ $node->badgeColor ?: '#6366f1' }}"
            >{{ $node->badgeText }}</span>
        @endif
    </a>
    @else
    <span @class(['text-sm py-1', 'text-slate-400 font-medium' => true, $node->cssClass ?? ''])>
        {{ $node->label }}
    </span>
    @endif
</li>
