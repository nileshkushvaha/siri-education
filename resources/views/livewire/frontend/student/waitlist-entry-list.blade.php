<div>
    @if(session('success'))
        <div class="mb-4 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    <x-account.card>
        @forelse($entries as $entry)
            <div wire:key="waitlist-{{ $entry->id }}" class="flex items-center justify-between py-4 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-white truncate">{{ $entry->instructor?->name ?? 'Instructor' }}</p>
                        <x-ui.badge :color="$entry->status->color()">{{ $entry->status->label() }}</x-ui.badge>
                    </div>
                    <p class="text-xs text-slate-400">
                        Joined {{ viewer_date($entry->joined_at) }}
                        @if($entry->notified_at)
                            &middot; Last notified {{ viewer_date($entry->notified_at) }}
                        @endif
                    </p>
                </div>
                <div class="text-right flex-shrink-0 ml-4">
                    @if($entry->status->value === 'waiting')
                        <button type="button" wire:click="leave({{ $entry->id }})"
                                class="inline-flex min-h-11 items-center px-3 py-2 rounded-lg bg-white/[0.06] text-xs font-semibold text-white hover:bg-white/[0.1] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
                            Leave
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">No waitlist entries yet</h3>
                <p class="text-slate-400 text-sm max-w-xs">Join an instructor's waitlist from their profile when no suitable time is available.</p>
            </div>
        @endforelse

        @if($entries->hasPages())
            <div class="mt-6 pt-4 border-t border-white/[0.04]">
                {{ $entries->links() }}
            </div>
        @endif
    </x-account.card>
</div>
