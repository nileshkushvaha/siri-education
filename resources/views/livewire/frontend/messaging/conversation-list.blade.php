<div>
    <x-account.card>
        @forelse($conversations as $conversation)
            @php($other = $conversation->student_id === auth()->id() ? $conversation->instructor : $conversation->student)
            <div wire:key="conversation-{{ $conversation->id }}" class="flex items-center justify-between py-4 {{ !$loop->last ? 'border-b border-edge' : '' }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-fg-strong truncate">{{ $other?->name ?? 'Unknown' }}</p>
                        @if($conversation->unread_count > 0)
                            <span class="inline-flex min-h-6 min-w-6 items-center justify-center rounded-full bg-indigo-500/20 px-1.5 text-xs font-bold text-indigo-600 dark:text-indigo-300" aria-label="{{ $conversation->unread_count }} unread">
                                {{ $conversation->unread_count }}
                            </span>
                        @endif
                    </div>
                    <p class="text-xs text-fg-muted truncate">
                        {{ class_basename($conversation->context_type) }}
                        &middot; {{ $conversation->status->label() }}
                        @if($conversation->last_message_at)
                            &middot; {{ $conversation->last_message_at->diffForHumans() }}
                        @endif
                    </p>
                </div>
                <div class="text-right flex-shrink-0 ml-4">
                    <a href="{{ route('dashboard.messages.show', $conversation) }}"
                       class="inline-flex min-h-11 items-center px-3 py-2 rounded-lg bg-surface-raised text-xs font-semibold text-fg-strong hover:bg-surface-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
                        Open
                    </a>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <h3 class="text-fg-muted font-semibold mb-2">No conversations yet</h3>
                <p class="text-fg-muted text-sm max-w-xs">Messaging opens automatically once you have a confirmed booking or active learning plan with someone.</p>
            </div>
        @endforelse

        @if($conversations->hasPages())
            <div class="mt-6 pt-4 border-t border-edge">
                {{ $conversations->links() }}
            </div>
        @endif
    </x-account.card>
</div>
