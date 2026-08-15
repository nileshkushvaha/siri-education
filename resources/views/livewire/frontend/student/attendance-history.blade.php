<div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-account.stat-card label="Completed" :value="(string) ($stats->completed ?? 0)" gradient="from-emerald-500 to-teal-500"
            icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-account.stat-card label="No Shows" :value="(string) ($stats->no_show ?? 0)" gradient="from-rose-500 to-red-500"
            icon="M6 18L18 6M6 6l12 12" />
        <x-account.stat-card label="Cancelled" :value="(string) ($stats->cancelled ?? 0)" gradient="from-slate-500 to-slate-600"
            icon="M6 18L18 6M6 6l12 12" />
        <x-account.stat-card label="Total Sessions" :value="(string) ($stats->total ?? 0)" gradient="from-indigo-500 to-violet-500"
            icon="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
    </div>

    <x-account.card title="Attendance History">
        @forelse($history as $booking)
            <div wire:key="attendance-{{ $booking->id }}" class="flex items-center justify-between py-4 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-sm font-medium text-white truncate">{{ $booking->type?->name ?? 'Session' }}</p>
                        <x-ui.badge :color="$booking->status->color()">{{ $booking->status->label() }}</x-ui.badge>
                    </div>
                    <p class="text-xs text-slate-400">with {{ $booking->instructor?->name ?? 'Teacher' }}</p>
                </div>
                <div class="text-right flex-shrink-0 ml-4">
                    <p class="text-sm font-medium text-slate-300">{{ viewer_date($booking->starts_at) }}</p>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">No attendance records yet</h3>
                <p class="text-slate-400 text-sm max-w-xs">Completed and missed sessions will appear here.</p>
            </div>
        @endforelse
    </x-account.card>
</div>
