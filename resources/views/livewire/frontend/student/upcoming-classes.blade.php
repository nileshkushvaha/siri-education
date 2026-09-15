{{-- Polled only while some listed lesson's join state can still change on its own. --}}
<div @if($poll) wire:poll.60s @endif>
    @if($classes->isEmpty())
        <x-account.card>
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <div class="w-16 h-16 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-indigo-400/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h3 class="text-fg-muted font-semibold mb-2">No upcoming classes</h3>
                <p class="text-fg-muted text-sm max-w-xs mb-5">Book a session with a teacher to get started.</p>
                <a href="{{ route('booking.create') }}" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all shadow-lg shadow-indigo-500/20">
                    Book a Class
                </a>
            </div>
        </x-account.card>
    @else
        <div class="space-y-4">
            @foreach([['Today', $today], ['Later', $later]] as [$heading, $rows])
                @if($rows->isNotEmpty())
                    <x-account.card :title="$heading" data-schedule-group="{{ strtolower($heading) }}">
                        @foreach($rows as $row)
                            @php($booking = $row['booking'])
                            <div wire:key="upcoming-class-{{ $booking->id }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 py-4 {{ !$loop->last ? 'border-b border-edge' : '' }}">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2 mb-1">
                                        <a href="{{ route('dashboard.my-bookings.show', $booking) }}" class="text-sm font-medium text-fg-strong truncate hover:text-indigo-600 dark:hover:text-indigo-300">{{ $booking->type?->name ?? 'Session' }}</a>
                                        @if($booking->isAwaitingCompletion())
                                            <x-ui.badge color="slate">Lesson ended · Completion pending</x-ui.badge>
                                        @else
                                            <x-ui.badge :color="$booking->status->color()">{{ $booking->status->label() }}</x-ui.badge>
                                        @endif
                                    </div>
                                    <p class="text-xs text-fg-muted">with {{ $booking->instructor?->name ?? 'Teacher' }} &middot; {{ $booking->location_type->label() }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-4">
                                    <div class="text-right">
                                        <p class="text-sm font-medium text-indigo-600 dark:text-indigo-300">{{ viewer_date($booking->starts_at, 'D, M j') }}</p>
                                        <p class="text-xs text-fg-muted mt-0.5">{{ viewer_time($booking->starts_at) }} – {{ viewer_time($booking->ends_at) }}</p>
                                    </div>
                                    {{-- The authoritative join state — never the legacy meeting_url column. --}}
                                    <x-student.join-action :state="$row['join']" :booking="$booking" :compact="true" />
                                </div>
                            </div>
                        @endforeach
                    </x-account.card>
                @endif
            @endforeach
        </div>
    @endif
</div>
