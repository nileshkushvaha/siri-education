<div>
    <x-account.card>
        @forelse($classes as $booking)
            <div wire:key="upcoming-class-{{ $booking->id }}" class="flex items-center justify-between py-4 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-white truncate">{{ $booking->type?->name ?? 'Session' }}</p>
                        <x-ui.badge :color="$booking->status->color()">{{ $booking->status->label() }}</x-ui.badge>
                    </div>
                    <p class="text-xs text-slate-400">with {{ $booking->instructor?->name ?? 'Teacher' }} &middot; {{ $booking->location_type->label() }}</p>
                </div>
                <div class="text-right flex-shrink-0 ml-4">
                    <p class="text-sm font-medium text-indigo-300">{{ $booking->starts_at->format('D, M j') }}</p>
                    <p class="text-xs text-slate-400 mt-0.5">{{ $booking->starts_at->format('g:i A') }} – {{ $booking->ends_at->format('g:i A') }}</p>
                    @if($booking->meeting_url)
                        <a href="{{ $booking->meeting_url }}" target="_blank" rel="noopener"
                           class="inline-block mt-2 text-xs font-semibold text-indigo-400 hover:text-indigo-300">
                            Join Class →
                        </a>
                    @endif
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <div class="w-16 h-16 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-indigo-400/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h3 class="text-slate-300 font-semibold mb-2">No upcoming classes</h3>
                <p class="text-slate-400 text-sm max-w-xs mb-5">Book a session with a teacher to get started.</p>
                <a href="{{ route('booking.create') }}" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all shadow-lg shadow-indigo-500/20">
                    Book a Class
                </a>
            </div>
        @endforelse
    </x-account.card>
</div>
