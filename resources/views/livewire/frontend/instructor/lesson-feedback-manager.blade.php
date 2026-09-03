<div class="space-y-6">
    @if (session('lessons-status'))
        <div class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 p-4 text-sm text-emerald-700 dark:text-emerald-200" role="status">
            {{ session('lessons-status') }}
        </div>
    @endif

    @if (session('lessons-error'))
        <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 p-4 text-sm text-rose-700 dark:text-rose-200" role="alert">
            {{ session('lessons-error') }}
        </div>
    @endif

    @error('form')
        <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 p-4 text-sm text-rose-700 dark:text-rose-200" role="alert">
            {{ $message }}
        </div>
    @enderror

    <div>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-[0.16em] text-indigo-600 dark:text-indigo-300">Upcoming Lessons</h2>
        <x-account.card>
            @forelse ($upcoming as $lesson)
                @php($join = $joinInfo[$lesson->id] ?? ['availability' => \App\Booking\Enums\MeetingJoinAvailability::Unavailable, 'url' => null])
                @php($lessonStartsAt = $lesson->starts_at->copy()->timezone($timezone))
                @php($lessonEndsAt = $lesson->ends_at->copy()->timezone($timezone))
                <div wire:key="upcoming-lesson-{{ $lesson->id }}" class="py-4 {{ ! $loop->last ? 'border-b border-edge' : '' }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <p class="text-sm font-medium text-fg-strong truncate">{{ $lesson->subject?->name ?? 'General' }}</p>
                                <x-ui.badge :color="$lesson->status->color()">{{ $lesson->status->label() }}</x-ui.badge>
                            </div>
                            <p class="text-xs text-fg-muted">Student: {{ $lesson->student?->name ?? 'Student' }}</p>
                            <p class="text-xs text-fg-muted mt-1">
                                {{ $lessonStartsAt->isToday() ? 'Today' : viewer_date($lessonStartsAt) }}
                                &middot; {{ viewer_time($lessonStartsAt) }} - {{ viewer_time($lessonEndsAt) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if($join['availability'] === \App\Booking\Enums\MeetingJoinAvailability::Available)
                                <a href="{{ $join['url'] }}" target="_blank" rel="noopener noreferrer"
                                   class="min-h-11 inline-flex items-center px-4 py-2 rounded-xl text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-500 transition"
                                   aria-label="Join the class for {{ $lesson->subject?->name ?? 'this lesson' }}">
                                    Join Class
                                </a>
                            @elseif(in_array($join['availability'], [\App\Booking\Enums\MeetingJoinAvailability::NotReady, \App\Booking\Enums\MeetingJoinAvailability::TooEarly], true))
                                <span class="min-h-11 inline-flex items-center px-4 py-2 rounded-xl text-xs font-semibold text-fg-muted border border-edge">
                                    {{ $join['availability']->label() }}
                                </span>
                            @endif
                            <button type="button" wire:click="toggleExpand('{{ $lesson->id }}')"
                                    class="min-h-11 px-4 py-2 rounded-lg text-xs font-semibold text-fg-muted border border-edge hover:bg-surface-hover transition"
                                    aria-expanded="{{ $expandedLessonId === $lesson->id ? 'true' : 'false' }}">
                                {{ $expandedLessonId === $lesson->id ? 'Hide' : 'View Details' }}
                            </button>
                        </div>
                    </div>

                    @if ($expandedLessonId === $lesson->id)
                        @include('livewire.frontend.instructor.lesson-detail-panel', ['lesson' => $lesson])
                    @endif
                </div>
            @empty
                <x-ui.empty-state title="No upcoming lessons." description="Your scheduled lessons will appear here." />
            @endforelse
        </x-account.card>
    </div>

    <div>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-[0.16em] text-emerald-600 dark:text-emerald-300">Recent Lessons</h2>
        <x-account.card>
            @forelse ($lessons as $lesson)
                @php($lessonStartsAt = $lesson->starts_at->copy()->timezone($timezone))
                <div wire:key="lesson-{{ $lesson->id }}" class="py-4 {{ ! $loop->last ? 'border-b border-edge' : '' }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <p class="text-sm font-medium text-fg-strong truncate">{{ $lesson->subject?->name ?? 'General' }}</p>
                                <p class="text-sm text-fg-muted truncate">{{ $lesson->student?->name ?? 'Student' }}</p>
                                <x-ui.badge :color="$lesson->status->color()">{{ $lesson->status->label() }}</x-ui.badge>
                                @if ($lesson->outcome !== null && $lesson->hasFinalizedOutcome())
                                    <x-ui.badge :color="$lesson->outcome->color()">{{ $lesson->outcome->label() }}</x-ui.badge>
                                @endif
                            </div>
                            <p class="text-xs text-fg-muted">
                                {{ viewer_datetime($lessonStartsAt) }}
                                @if ($lesson->outcome === $completedOutcome && $lesson->hasFinalizedOutcome() && ($existingFeedback[$lesson->id] ?? null))
                                    &middot; Feedback submitted
                                @endif
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" wire:click="toggleExpand('{{ $lesson->id }}')"
                                    class="min-h-11 px-4 py-2 rounded-lg text-xs font-semibold text-fg-muted border border-edge hover:bg-surface-hover transition"
                                    aria-expanded="{{ $expandedLessonId === $lesson->id ? 'true' : 'false' }}">
                                {{ $expandedLessonId === $lesson->id ? 'Hide' : 'View Details' }}
                            </button>
                        </div>
                    </div>

                    @if ($expandedLessonId === $lesson->id)
                        @include('livewire.frontend.instructor.lesson-detail-panel', ['lesson' => $lesson])
                    @endif
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <h3 class="text-fg-muted font-semibold mb-2">No lessons yet</h3>
                    <p class="text-fg-muted text-sm max-w-xs">Your lessons will appear here once they are scheduled.</p>
                </div>
            @endforelse

            @if ($lessons->hasPages())
                <div class="pt-4">
                    {{ $lessons->links() }}
                </div>
            @endif
        </x-account.card>
    </div>
</div>
