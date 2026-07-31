<li>
    @if(! $node->link->isEmpty())
        <a
            href="{{ $node->link->url }}"
            target="{{ $node->link->target }}"
            @if($node->link->rel) rel="{{ $node->link->rel }}" @endif
            class="group inline-flex min-h-8 items-center gap-2 text-base text-slate-300 transition hover:translate-x-0.5 hover:text-violet-200 focus:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/20"
            @if($node->isActive) aria-current="page" @endif
            @if($node->link->isExternal()) aria-label="{{ $node->label }} (opens in new tab)" @endif
        >
            @if($node->icon)
                @if(str_starts_with($node->icon, 'heroicon-'))
                    <x-filament::icon :icon="$node->icon" class="h-4 w-4 shrink-0 text-violet-400 transition-transform group-hover:scale-110" />
                @else
                    <span class="{{ $node->icon }} h-4 w-4 shrink-0 text-violet-400" aria-hidden="true"></span>
                @endif
            @endif
            {{ $node->label }}
            @if($node->badgeText)<x-ui.badge color="indigo">{{ $node->badgeText }}</x-ui.badge>@endif
        </a>
    @else
        <span class="text-base font-semibold text-slate-200">{{ $node->label }}</span>
    @endif

    @if($node->hasChildren())
        <ul class="mt-3 space-y-3 border-l border-violet-400/15 pl-4" role="list">
            @foreach($node->children as $child)
                @include('livewire.frontend.layout.partials.footer-nav-node', ['node' => $child])
            @endforeach
        </ul>
    @endif
</li>
