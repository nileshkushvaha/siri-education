<div>
    @if($enabled && ! $hidden && filled($message))
        <aside class="bg-slate-950 px-4 py-2 text-sm text-white" aria-label="Announcement">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4">
                <p class="min-w-0 truncate">
                    {{ $message }}
                    @if($url && $actionLabel)
                        <a href="{{ $url }}" class="ml-2 font-semibold text-indigo-200 underline underline-offset-4 hover:text-white">{{ $actionLabel }}</a>
                    @endif
                </p>
                <button type="button" wire:click="dismiss" class="rounded-lg p-1 text-slate-400 transition hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/20">
                    <span class="sr-only">Dismiss announcement</span>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </aside>
    @endif
</div>
