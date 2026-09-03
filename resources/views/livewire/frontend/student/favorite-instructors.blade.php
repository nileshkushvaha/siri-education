<div>
    @if(session('success'))
        <div class="mb-4 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    <x-account.card title="Favorite Instructors">
        @forelse($favorites as $instructor)
            <div wire:key="favorite-instructor-{{ $instructor->id }}" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 py-4 {{ !$loop->last ? 'border-b border-edge' : '' }}">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 flex items-center justify-center text-white font-bold shrink-0 overflow-hidden">
                        @if($instructor->avatarThumbUrl)
                            <img src="{{ $instructor->avatarThumbUrl }}" alt="{{ $instructor->name }}" class="w-full h-full object-cover">
                        @else
                            {{ strtoupper(substr($instructor->name, 0, 1)) }}
                        @endif
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-fg-strong truncate">{{ $instructor->name }}</p>
                        <p class="text-xs text-fg-muted truncate">{{ $instructor->profile?->headline ?? 'Instructor' }}</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('instructors.show', $instructor) }}" class="px-3 py-2 rounded-lg border border-edge text-xs font-semibold text-fg-muted hover:text-fg-strong hover:bg-surface-hover">
                        View
                    </a>
                    <button type="button" wire:click="remove({{ $instructor->id }})" class="px-3 py-2 rounded-lg border border-rose-500/20 text-xs font-semibold text-rose-700 dark:text-rose-200 hover:bg-rose-500/10">
                        Remove
                    </button>
                </div>
            </div>
        @empty
            <div class="py-12 text-center">
                <div class="w-16 h-16 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-indigo-300/70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                    </svg>
                </div>
                <p class="text-sm font-semibold text-fg-muted">No favorite instructors yet.</p>
                <p class="text-sm text-fg-faint mt-1">Browse approved instructors and save the ones you want to learn with.</p>
                <a href="{{ route('instructors.index') }}" class="inline-block mt-5 px-5 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500 transition">
                    Browse Instructors
                </a>
            </div>
        @endforelse
    </x-account.card>
</div>
