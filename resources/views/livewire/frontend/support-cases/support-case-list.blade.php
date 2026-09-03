<div>
    <x-account.card>
        @forelse($cases as $case)
            <div wire:key="support-case-{{ $case->id }}" class="flex items-center justify-between py-4 {{ !$loop->last ? 'border-b border-edge' : '' }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-fg-strong truncate">{{ $case->subject }}</p>
                    </div>
                    <p class="text-xs text-fg-muted truncate">
                        {{ $case->case_number }}
                        &middot; {{ $case->category->label() }}
                        &middot; {{ viewer_date($case->opened_at) }}
                    </p>
                </div>
                <div class="text-right flex-shrink-0 ml-4 flex items-center gap-4">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-surface-raised text-fg-strong">
                        {{ $case->status->label() }}
                    </span>
                    <a href="{{ route('dashboard.support-cases.show', $case) }}"
                       class="inline-flex min-h-11 items-center px-3 py-2 rounded-lg bg-surface-raised text-xs font-semibold text-fg-strong hover:bg-surface-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
                        View
                    </a>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <h3 class="text-fg-muted font-semibold mb-2">No support cases yet</h3>
                <p class="text-fg-muted text-sm max-w-xs">Cases you open with support will appear here, with status and replies.</p>
            </div>
        @endforelse

        @if($cases->hasPages())
            <div class="mt-6 pt-4 border-t border-edge">
                {{ $cases->links() }}
            </div>
        @endif
    </x-account.card>
</div>
