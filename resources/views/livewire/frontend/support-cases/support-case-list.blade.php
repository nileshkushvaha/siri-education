<div>
    <x-account.card>
        @forelse($cases as $case)
            <div wire:key="support-case-{{ $case->id }}" class="flex items-center justify-between py-4 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-white truncate">{{ $case->subject }}</p>
                    </div>
                    <p class="text-xs text-slate-400 truncate">
                        {{ $case->case_number }}
                        &middot; {{ $case->category->label() }}
                        &middot; {{ $case->opened_at->format('M j, Y') }}
                    </p>
                </div>
                <div class="text-right flex-shrink-0 ml-4 flex items-center gap-4">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-white/[0.06] text-white">
                        {{ $case->status->label() }}
                    </span>
                    <a href="{{ route('dashboard.support-cases.show', $case) }}"
                       class="inline-flex min-h-11 items-center px-3 py-2 rounded-lg bg-white/[0.06] text-xs font-semibold text-white hover:bg-white/[0.1] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
                        View
                    </a>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">No support cases yet</h3>
                <p class="text-slate-400 text-sm max-w-xs">Cases you open with support will appear here, with status and replies.</p>
            </div>
        @endforelse

        @if($cases->hasPages())
            <div class="mt-6 pt-4 border-t border-white/[0.04]">
                {{ $cases->links() }}
            </div>
        @endif
    </x-account.card>
</div>
