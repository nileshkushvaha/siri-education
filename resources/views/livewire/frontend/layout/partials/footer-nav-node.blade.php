<li>
    @if(! $node->link->isEmpty())
        <a
            href="{{ $node->link->url }}"
            target="{{ $node->link->target }}"
            @if($node->link->rel) rel="{{ $node->link->rel }}" @endif
            class="inline-flex items-center gap-2 text-sm text-slate-400 transition hover:text-indigo-200 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/20"
            @if($node->isActive) aria-current="page" @endif
            @if($node->link->isExternal()) aria-label="{{ $node->label }} (opens in new tab)" @endif
        >
            {{ $node->label }}
            @if($node->badgeText)<x-ui.badge color="indigo">{{ $node->badgeText }}</x-ui.badge>@endif
        </a>
    @else
        <span class="text-sm font-semibold text-slate-300">{{ $node->label }}</span>
    @endif

    @if($node->hasChildren())
        <ul class="mt-2 space-y-2 border-l border-white/10 pl-4" role="list">
            @foreach($node->children as $child)
                @include('livewire.frontend.layout.partials.footer-nav-node', ['node' => $child])
            @endforeach
        </ul>
    @endif
</li>
