<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Analytics period">
        @foreach ($periods as $option)
            <button type="button" wire:click="setPeriod('{{ $option->value }}')"
                    aria-pressed="{{ $data->period === $option ? 'true' : 'false' }}"
                    class="min-h-9 rounded-full px-4 text-xs font-semibold transition {{ $data->period === $option ? 'bg-indigo-500 text-white' : 'border border-edge text-fg-muted hover:bg-surface-hover' }}">
                {{ $option->label() }}
            </button>
        @endforeach
    </div>

    @if($data->students['total'] === 0)
        <x-account.card>
            <x-ui.empty-state title="Your analytics will appear after you complete your first lesson." />
        </x-account.card>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-account.stat-card label="Students" :value="(string) $data->students['total']" gradient="from-indigo-500 to-violet-500"
                icon="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8zm6 3c0-1.1-1.79-2-4-2M3 15c0-1.1 1.79-2 4-2" />
            <x-account.stat-card label="Lessons" :value="(string) $data->lessons['total']" gradient="from-emerald-500 to-teal-500"
                icon="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            <x-account.stat-card label="Rating" :value="$data->quality['average_rating'] !== null ? number_format($data->quality['average_rating'], 1) : '—'" gradient="from-amber-500 to-orange-500"
                icon="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69z" />
            <x-account.stat-card label="Homework" :value="(string) $data->homework['assigned']" gradient="from-pink-500 to-rose-500"
                icon="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-account.card>
                <div class="mb-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600 dark:text-indigo-300">Students</p>
                    <h2 class="mt-1 text-lg font-semibold text-fg-strong">{{ $data->period->label() }}</h2>
                </div>
                <dl class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-xl bg-surface-raised p-3">
                        <dt class="text-xs text-fg-muted">Total</dt>
                        <dd class="mt-1 text-xl font-bold text-fg-strong">{{ $data->students['total'] }}</dd>
                    </div>
                    <div class="rounded-xl bg-surface-raised p-3">
                        <dt class="text-xs text-fg-muted">Active</dt>
                        <dd class="mt-1 text-xl font-bold text-fg-strong">{{ $data->students['active'] }}</dd>
                    </div>
                    <div class="rounded-xl bg-surface-raised p-3">
                        <dt class="text-xs text-fg-muted">New</dt>
                        <dd class="mt-1 text-xl font-bold text-fg-strong">{{ $data->students['new_this_period'] }}</dd>
                    </div>
                </dl>
                <a href="{{ route('dashboard.instructor.students') }}" class="mt-4 inline-flex items-center text-xs font-semibold text-indigo-600 dark:text-indigo-300 hover:text-indigo-700 hover:dark:text-indigo-200">View students &rarr;</a>
            </x-account.card>

            <x-account.card>
                <div class="mb-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-600 dark:text-emerald-300">Lessons</p>
                    <h2 class="mt-1 text-lg font-semibold text-fg-strong">{{ $data->period->label() }}</h2>
                </div>
                <div class="space-y-2">
                    <div class="flex items-center justify-between rounded-xl bg-surface-raised p-3"><span class="text-sm text-fg-muted">Completed</span><span class="font-bold text-fg-strong">{{ $data->lessons['completed'] }}</span></div>
                    <div class="flex items-center justify-between rounded-xl bg-surface-raised p-3"><span class="text-sm text-fg-muted">Upcoming</span><span class="font-bold text-fg-strong">{{ $data->lessons['upcoming'] }}</span></div>
                    <div class="flex items-center justify-between rounded-xl bg-surface-raised p-3"><span class="text-sm text-fg-muted">No shows</span><span class="font-bold text-fg-strong">{{ $data->lessons['no_show'] }}</span></div>
                    <div class="flex items-center justify-between rounded-xl bg-surface-raised p-3"><span class="text-sm text-fg-muted">Cancelled</span><span class="font-bold text-fg-strong">{{ $data->lessons['cancelled'] }}</span></div>
                </div>
                <a href="{{ route('dashboard.instructor.lessons') }}" class="mt-4 inline-flex items-center text-xs font-semibold text-indigo-600 dark:text-indigo-300 hover:text-indigo-700 hover:dark:text-indigo-200">View lessons &rarr;</a>
            </x-account.card>

            <x-account.card>
                <div class="mb-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-600 dark:text-amber-300">Rating</p>
                    <h2 class="mt-1 text-lg font-semibold text-fg-strong">Quality</h2>
                </div>
                @if($data->quality['total_reviews'] > 0)
                    <div class="flex items-end gap-3">
                        <p class="text-3xl font-bold text-fg-strong">{{ number_format($data->quality['average_rating'], 1) }} <span class="text-amber-600 dark:text-amber-300">&#9733;</span></p>
                        <p class="pb-1 text-sm text-fg-muted">{{ $data->quality['total_reviews'] }} {{ Str::plural('review', $data->quality['total_reviews']) }}</p>
                    </div>
                @else
                    <x-ui.empty-state title="Reviews will appear after students complete lessons." />
                @endif
                <a href="{{ route('dashboard.instructor.quality-insights') }}" class="mt-4 inline-flex items-center text-xs font-semibold text-indigo-600 dark:text-indigo-300 hover:text-indigo-700 hover:dark:text-indigo-200">View reviews &rarr;</a>
            </x-account.card>

            <x-account.card>
                <div class="mb-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-pink-600 dark:text-pink-300">Homework</p>
                    <h2 class="mt-1 text-lg font-semibold text-fg-strong">{{ $data->period->label() }}</h2>
                </div>
                <div class="space-y-2">
                    <div class="flex items-center justify-between rounded-xl bg-surface-raised p-3"><span class="text-sm text-fg-muted">Assigned</span><span class="font-bold text-fg-strong">{{ $data->homework['assigned'] }}</span></div>
                    <div class="flex items-center justify-between rounded-xl bg-surface-raised p-3"><span class="text-sm text-fg-muted">Submitted</span><span class="font-bold text-fg-strong">{{ $data->homework['submitted'] }}</span></div>
                    <div class="flex items-center justify-between rounded-xl bg-surface-raised p-3"><span class="text-sm text-fg-muted">Graded</span><span class="font-bold text-fg-strong">{{ $data->homework['graded'] }}</span></div>
                </div>
                <a href="{{ route('dashboard.instructor.homework') }}" class="mt-4 inline-flex items-center text-xs font-semibold text-indigo-600 dark:text-indigo-300 hover:text-indigo-700 hover:dark:text-indigo-200">View homework &rarr;</a>
            </x-account.card>
        </div>

        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-violet-600 dark:text-violet-300">Advanced Insights</p>
            <h2 class="mt-1 text-lg font-semibold text-fg-strong">Teaching Trends</h2>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-fg-muted">Completed Lessons</p>
                <p class="mt-2 text-3xl font-bold text-fg-strong">{{ $insights->lessons->completedCurrent }}</p>
                @if($insights->lessons->hasComparison)
                    @if($insights->lessons->completedChangePercent === null)
                        <p class="mt-2 text-xs text-fg-muted">No completed lessons in the previous period to compare.</p>
                    @else
                        <p class="mt-2 text-xs {{ $insights->lessons->completedChangePercent >= 0 ? 'text-emerald-600 dark:text-emerald-300' : 'text-rose-600 dark:text-rose-300' }}">
                            {{ $insights->lessons->completedChangePercent >= 0 ? '&uarr;' : '&darr;' }} {{ abs($insights->lessons->completedChangePercent) }}% compared with previous period
                        </p>
                    @endif
                @else
                    <p class="mt-2 text-xs text-fg-muted">No comparison available for All time.</p>
                @endif
            </x-account.card>

            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-fg-muted">Cancelled Lessons</p>
                <p class="mt-2 text-3xl font-bold text-fg-strong">{{ $insights->lessons->cancelledCurrent }}</p>
                @if($insights->lessons->hasComparison)
                    <p class="mt-2 text-xs text-fg-muted">Previous period: {{ $insights->lessons->cancelledPrevious }}</p>
                @endif
            </x-account.card>

            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-wide text-fg-muted">No Shows</p>
                <p class="mt-2 text-3xl font-bold text-fg-strong">{{ $insights->lessons->noShowCurrent }}</p>
                @if($insights->lessons->hasComparison)
                    <p class="mt-2 text-xs text-fg-muted">Previous period: {{ $insights->lessons->noShowPrevious }}</p>
                @endif
            </x-account.card>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-600 dark:text-amber-300">Quality Trends</p>
                <h2 class="mt-1 text-lg font-semibold text-fg-strong">Student Satisfaction</h2>
                @if($insights->quality->reviewCountCurrent > 0 || $insights->quality->reviewCountPrevious > 0)
                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-surface-raised p-3">
                            <p class="text-xs text-fg-muted">Rating (current period)</p>
                            <p class="mt-1 text-xl font-bold text-fg-strong">{{ $insights->quality->averageRatingCurrent !== null ? number_format($insights->quality->averageRatingCurrent, 1) : '—' }}</p>
                        </div>
                        <div class="rounded-xl bg-surface-raised p-3">
                            <p class="text-xs text-fg-muted">Rating (previous period)</p>
                            <p class="mt-1 text-xl font-bold text-fg-strong">{{ $insights->quality->hasComparison && $insights->quality->averageRatingPrevious !== null ? number_format($insights->quality->averageRatingPrevious, 1) : '—' }}</p>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-fg-muted">{{ $insights->quality->reviewCountCurrent }} {{ Str::plural('review', $insights->quality->reviewCountCurrent) }} this period</p>
                @else
                    <div class="mt-4">
                        <x-ui.empty-state title="Reviews will appear after students complete lessons." />
                    </div>
                @endif
            </x-account.card>

            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-indigo-600 dark:text-indigo-300">Student Engagement</p>
                <h2 class="mt-1 text-lg font-semibold text-fg-strong">Student Activity</h2>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-surface-raised p-3">
                        <p class="text-xs text-fg-muted">Active students</p>
                        <p class="mt-1 text-xl font-bold text-fg-strong">{{ $insights->students->activeStudents }}</p>
                    </div>
                    <div class="rounded-xl bg-surface-raised p-3">
                        <p class="text-xs text-fg-muted">Without upcoming lesson</p>
                        <p class="mt-1 text-xl font-bold text-fg-strong">{{ $insights->students->studentsWithoutUpcomingLesson }}</p>
                    </div>
                </div>
            </x-account.card>
        </div>
    @endif
</div>
