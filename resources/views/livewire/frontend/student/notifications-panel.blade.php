<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-white">
                Notifications
                @if($unreadCount > 0)
                    <span class="ml-2 inline-flex items-center justify-center min-w-[1.5rem] h-6 px-2 rounded-full bg-indigo-500/20 text-indigo-300 text-xs font-semibold">
                        {{ $unreadCount }}
                    </span>
                @endif
            </h1>
            <p class="text-slate-400 text-sm mt-1">Your alerts and updates.</p>
        </div>
        @if($unreadCount > 0)
            <button wire:click="markAllRead"
                    class="px-4 py-2 rounded-xl text-sm font-medium text-slate-300 border border-white/[0.08] hover:text-white hover:border-indigo-500/30 hover:bg-indigo-500/10 transition-all">
                Mark all read
            </button>
        @endif
    </div>

    <x-account.card>
        @if($notifications->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <div class="w-16 h-16 rounded-2xl bg-slate-500/10 border border-white/[0.06] flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                </div>
                <h3 class="text-slate-300 font-semibold mb-2">No notifications</h3>
                <p class="text-slate-400 text-sm max-w-xs">You're all caught up. Notifications about your bookings, homework, and account will appear here.</p>
            </div>
        @else
            <div class="divide-y divide-white/[0.04]">
                @foreach($notifications as $notification)
                    @php
                        $isUnread = is_null($notification->read_at);
                        $title    = $notification->data['title']   ?? 'Notification';
                        $body     = $notification->data['message'] ?? ($notification->data['body'] ?? null);
                        $url      = $notification->data['url']     ?? null;
                    @endphp
                    <div wire:key="notification-{{ $notification->id }}" class="flex items-start gap-4 py-4 {{ $isUnread ? 'opacity-100' : 'opacity-70' }}">
                        <div class="mt-1 flex-shrink-0">
                            @if($isUnread)
                                <span class="w-2 h-2 rounded-full bg-indigo-400 block"></span>
                            @else
                                <span class="w-2 h-2 rounded-full bg-transparent block"></span>
                            @endif
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    @if($url)
                                        <a href="{{ $url }}" class="text-sm font-medium text-white hover:text-indigo-300 transition truncate block">
                                            {{ $title }}
                                        </a>
                                    @else
                                        <p class="text-sm font-medium text-white truncate">{{ $title }}</p>
                                    @endif
                                    @if($body)
                                        <p class="text-xs text-slate-400 mt-0.5 line-clamp-2">{{ $body }}</p>
                                    @endif
                                    <p class="text-xs text-slate-400 mt-1">{{ $notification->created_at->diffForHumans() }}</p>
                                </div>
                                @if($isUnread)
                                    <button wire:click="markRead('{{ $notification->id }}')"
                                            class="text-xs text-slate-400 hover:text-indigo-400 transition whitespace-nowrap flex-shrink-0">
                                        Mark read
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if($notifications->hasPages())
                <div class="mt-6 pt-4 border-t border-white/[0.04]">
                    {{ $notifications->links() }}
                </div>
            @endif
        @endif
    </x-account.card>
</div>
