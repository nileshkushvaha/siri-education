@props(['notifications' => collect()])

<div class="rounded-2xl border border-edge p-5" style="background:var(--portal-surface-raised)">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-semibold text-fg-strong">Notifications</h3>
        @if($notifications->isNotEmpty())
            <span class="text-xs text-indigo-600 dark:text-indigo-400 font-medium">{{ $notifications->count() }} new</span>
        @endif
    </div>

    @forelse($notifications as $notification)
        <div class="flex items-start gap-3 py-2.5 border-b border-edge last:border-0">
            <div class="w-2 h-2 rounded-full bg-indigo-400 mt-1.5 flex-shrink-0"></div>
            <div class="min-w-0">
                <p class="text-fg-muted text-xs font-medium truncate">{{ $notification->data['title'] ?? 'Notification' }}</p>
                <p class="text-fg-muted text-[10px] mt-0.5">{{ $notification->created_at->diffForHumans() }}</p>
            </div>
        </div>
    @empty
        <div class="flex flex-col items-center justify-center py-6 text-center">
            <svg class="w-8 h-8 text-slate-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            <p class="text-fg-muted text-xs">No new notifications</p>
        </div>
    @endforelse
</div>
