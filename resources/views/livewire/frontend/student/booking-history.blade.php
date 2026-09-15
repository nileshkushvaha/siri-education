<div>
    @if($nextUp)
        @php $next = $nextUp['booking']; @endphp
        {{-- Pinned above the list, independent of filter and page: the
             soonest lesson not yet ended and its join action. Polled only
             while the join state can still change on its own. --}}
        <div class="mb-4" data-next-up="{{ $next->id }}" @if($nextUp['join']->poll) wire:poll.60s @endif>
        <x-account.card>
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-4">
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold uppercase tracking-[.18em] text-indigo-600 dark:text-indigo-300">{{ $next->hasStarted() ? 'In progress' : ($nextUp['today'] ? 'Next up · Today' : 'Next up') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <a href="{{ route('dashboard.my-bookings.show', ['booking' => $next, ...$listQuery]) }}" class="text-lg font-bold text-fg-strong hover:text-indigo-600 dark:hover:text-indigo-300">{{ $next->type?->name ?? 'Session' }}</a>
                        <x-ui.badge :color="$next->status->color()">{{ $next->status->label() }}</x-ui.badge>
                    </div>
                    <p class="mt-1 text-sm text-fg-muted">
                        with {{ $next->instructor?->name ?? 'Teacher' }}
                        <span class="mx-1 text-fg-faint">&middot;</span>
                        {{ viewer_datetime($next->starts_at, 'D, j M · g:i A') }}–{{ viewer_time($next->ends_at) }}
                    </p>
                </div>
                <x-student.join-action :state="$nextUp['join']" :booking="$next" size="md" class="shrink-0" />
            </div>
        </x-account.card>
        </div>
    @endif

    {{-- Filters: status chips on wide screens, a select on narrow ones,
         plus the page-size control. Both reset pagination on change. --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="hidden flex-wrap items-center gap-2 sm:flex" role="group" aria-label="Filter by status">
            <button type="button" wire:click="setStatusFilter('')"
                    aria-pressed="{{ $statusFilter === '' ? 'true' : 'false' }}"
                    class="min-h-9 rounded-full border px-3.5 text-xs font-bold transition {{ $statusFilter === '' ? 'border-indigo-500 bg-indigo-500/10 text-indigo-700 dark:text-indigo-200' : 'border-edge bg-surface-raised text-fg-muted hover:border-indigo-400/40 hover:text-fg-strong' }}">
                All
            </button>
            @foreach($statuses as $status)
                <button type="button" wire:click="setStatusFilter('{{ $status->value }}')"
                        aria-pressed="{{ $statusFilter === $status->value ? 'true' : 'false' }}"
                        class="min-h-9 rounded-full border px-3.5 text-xs font-bold transition {{ $statusFilter === $status->value ? 'border-indigo-500 bg-indigo-500/10 text-indigo-700 dark:text-indigo-200' : 'border-edge bg-surface-raised text-fg-muted hover:border-indigo-400/40 hover:text-fg-strong' }}">
                    {{ $status->label() }}
                </button>
            @endforeach
        </div>

        <div class="sm:hidden">
            <label for="booking-status-filter" class="sr-only">Filter by status</label>
            <select id="booking-status-filter" wire:model.live="statusFilter"
                    class="min-h-11 rounded-xl border border-edge bg-surface-raised px-3 py-2 text-sm text-fg-muted focus:border-indigo-500/40 focus:outline-none">
                <option value="">All statuses</option>
                @foreach($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-center gap-2">
            <label for="booking-per-page" class="text-xs font-semibold text-fg-muted">Per page</label>
            <select id="booking-per-page" wire:model.live="perPage"
                    class="min-h-9 rounded-xl border border-edge bg-surface-raised px-2.5 py-1.5 text-xs font-semibold text-fg-muted focus:border-indigo-500/40 focus:outline-none">
                @foreach($perPageOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <x-account.card>
        <div wire:loading.class="opacity-50" wire:target="statusFilter,perPage,setStatusFilter,gotoPage,nextPage,previousPage">
            @forelse($history as $booking)
                @php
                    $isActive = ! $booking->status->isTerminal();
                    $awaitingPayment = $isActive && $booking->payment_status->isPayable();
                @endphp
                <div wire:key="booking-history-{{ $booking->id }}"
                     class="group relative flex flex-wrap items-center justify-between gap-x-4 gap-y-3 py-4 transition hover:bg-surface-hover {{ !$loop->last ? 'border-b border-edge' : '' }}">
                    <div class="min-w-0 flex-1">
                        <div class="mb-1 flex flex-wrap items-center gap-2">
                            {{-- The whole row is clickable through this stretched
                                 link, so the keyboard target and the pointer
                                 target are the same single element. --}}
                            <a href="{{ route('dashboard.my-bookings.show', ['booking' => $booking, ...$listQuery]) }}"
                               class="text-sm font-semibold text-fg-strong before:absolute before:inset-0 before:content-[''] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 group-hover:text-indigo-600 dark:group-hover:text-indigo-300">
                                {{ $booking->type?->name ?? 'Session' }}
                            </a>
                            @if($booking->isAwaitingCompletion())
                                <x-ui.badge color="slate">Lesson ended · Completion pending</x-ui.badge>
                            @else
                                <x-ui.badge :color="$booking->status->color()">{{ $booking->status->label() }}</x-ui.badge>
                            @endif
                            @if($awaitingPayment)
                                <x-ui.badge :color="$booking->payment_status->color()">{{ $booking->payment_status->label() }}</x-ui.badge>
                            @endif
                        </div>
                        <p class="text-xs text-fg-muted">
                            with
                            @if($booking->instructor)
                                {{-- relative + z-10 keeps this link above the row's
                                     stretched overlay so it stays independently clickable. --}}
                                <a href="{{ route('instructors.show', $booking->instructor) }}" target="_blank" rel="noopener"
                                   class="relative z-10 text-indigo-600 hover:underline hover:text-indigo-700 dark:text-indigo-300 hover:dark:text-indigo-200">{{ $booking->instructor->name }}</a>
                            @else
                                Teacher
                            @endif
                            <span class="mx-1 text-fg-faint">&middot;</span>{{ $booking->reference }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        @if(($joinStates[$booking->id] ?? null)?->isAvailable())
                            {{-- relative + z-10 lifts the button above the row's
                                 stretched link so it opens the meeting, not the page. --}}
                            <x-student.join-action :state="$joinStates[$booking->id]" :booking="$booking" :compact="true" class="relative z-10" />
                        @endif
                        <div class="text-right">
                            <p class="text-sm font-medium text-fg-strong">{{ viewer_date($booking->starts_at) }}</p>
                            <p class="mt-0.5 text-xs text-fg-muted">{{ viewer_time($booking->starts_at) }}</p>
                        </div>
                        <svg class="h-4 w-4 text-fg-faint transition group-hover:translate-x-0.5 group-hover:text-indigo-500" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                        </svg>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-16 text-center">
                    <h3 class="mb-2 font-semibold text-fg-muted">No bookings found</h3>
                    <p class="max-w-xs text-sm text-fg-muted">
                        @if($statusFilter !== '')
                            No bookings match this status filter.
                        @else
                            Your booking history will appear here.
                        @endif
                    </p>
                    @if($statusFilter !== '')
                        <x-ui.button type="button" variant="ghost" size="sm" class="mt-3" wire:click="clearFilters">Clear filter</x-ui.button>
                    @endif
                </div>
            @endforelse
        </div>

        @if($history->total() > 0)
            <div class="mt-6 border-t border-edge pt-4">
                <p class="mb-3 text-xs text-fg-muted">
                    Showing {{ $history->firstItem() }}–{{ $history->lastItem() }} of {{ $history->total() }}
                    {{ $history->total() === 1 ? 'booking' : 'bookings' }}
                </p>
                @if($history->hasPages())
                    {{ $history->links() }}
                @endif
            </div>
        @endif
    </x-account.card>
</div>
