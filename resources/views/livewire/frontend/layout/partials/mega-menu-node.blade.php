@php $isEmpty = $node->link->isEmpty(); @endphp

<div class="rounded-xl p-2 transition hover:bg-slate-50 dark:hover:bg-white/5">
    @if(! $isEmpty)
        <a
            href="{{ $node->link->url }}"
            target="{{ $node->link->target }}"
            @if($node->link->rel) rel="{{ $node->link->rel }}" @endif
            class="group flex items-start gap-3 rounded-xl focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-100 dark:focus-visible:ring-indigo-400/20"
            @if($node->isActive) aria-current="page" @endif
        >
            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-400/10 dark:text-indigo-200">
                @if($node->icon)
                    {{ $node->icon }}
                @else
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                @endif
            </span>
            <span class="min-w-0">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-900 group-hover:text-indigo-700 dark:text-white dark:group-hover:text-indigo-200">
                    {{ $node->label }}
                    @if($node->badgeText)<x-ui.badge color="indigo">{{ $node->badgeText }}</x-ui.badge>@endif
                </span>
                <span class="mt-0.5 block truncate text-xs text-slate-500 dark:text-slate-400">{{ $node->link->url }}</span>
            </span>
        </a>
    @else
        <p class="px-1 text-sm font-semibold text-slate-900 dark:text-white">{{ $node->label }}</p>
    @endif

    @if($node->hasChildren())
        <ul class="mt-2 space-y-1 border-l border-slate-200 pl-4 dark:border-white/10" role="list">
            @foreach($node->children as $child)
                <li>
                    <a href="{{ $child->link->url }}" class="block rounded-lg px-2 py-1.5 text-sm text-slate-600 transition hover:bg-slate-100 hover:text-indigo-700 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-indigo-200">
                        {{ $child->label }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
