@php
    $hasChildren = $node->hasChildren();
    $isEmpty = $node->link->isEmpty();
    $panelId = 'mobile-nav-'.$node->id;
@endphp

<li x-data="{ open: {{ $node->isActive || $node->isAncestorActive ? 'true' : 'false' }} }">
    <div class="flex items-center gap-1" style="padding-left: {{ $depth * 0.75 }}rem">
        @if(! $isEmpty)
            <a
                href="{{ $node->link->url }}"
                target="{{ $node->link->target }}"
                @if($node->link->rel) rel="{{ $node->link->rel }}" @endif
                class="flex min-h-11 flex-1 items-center justify-between rounded-xl px-3 text-sm font-semibold transition {{ $node->isActive ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-400/10 dark:text-indigo-200' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-white/10' }}"
                @if($node->isActive) aria-current="page" @endif
            >
                <span>{{ $node->label }}</span>
                @if($node->badgeText)<x-ui.badge color="indigo">{{ $node->badgeText }}</x-ui.badge>@endif
            </a>
        @else
            <span class="flex min-h-11 flex-1 items-center rounded-xl px-3 text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $node->label }}</span>
        @endif

        @if($hasChildren)
            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="{{ $panelId }}" class="flex h-11 w-11 items-center justify-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-indigo-600 dark:text-slate-300 dark:hover:bg-white/10">
                <span class="sr-only">Toggle {{ $node->label }}</span>
                <svg class="h-4 w-4 transition" x-bind:class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
        @endif
    </div>

    @if($hasChildren)
        <ul id="{{ $panelId }}" x-show="open" x-collapse x-cloak class="mt-1 space-y-1" role="list">
            @foreach($node->children as $child)
                @include('livewire.frontend.layout.partials.mobile-nav-node', ['node' => $child, 'depth' => $depth + 1])
            @endforeach
        </ul>
    @endif
</li>
