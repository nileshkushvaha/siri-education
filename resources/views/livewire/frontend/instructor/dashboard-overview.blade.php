<div class="space-y-6">
    <section class="relative overflow-hidden rounded-3xl border border-indigo-400/20 bg-gradient-to-br from-indigo-600/20 via-slate-900 to-violet-600/10 p-5 sm:p-7">
        <div class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-indigo-500/15 blur-3xl"></div>
        <div class="relative flex flex-col gap-6 xl:flex-row xl:items-center xl:justify-between">
            <div>
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <span class="rounded-full border border-indigo-400/20 bg-indigo-400/10 px-3 py-1 text-xs font-semibold text-indigo-200">Instructor workspace</span>
                    @if($todayCount > 0)
                        <span class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-200">{{ $todayCount }} {{ Str::plural('lesson', $todayCount) }} today</span>
                    @endif
                </div>
                <h2 class="max-w-2xl text-2xl font-bold tracking-tight text-white sm:text-3xl">Plan the day. Teach with confidence.</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">Your lessons, student learning plans, reviews, and earnings are organized in one focused workspace.</p>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap xl:justify-end">
                <a href="{{ route('dashboard.instructor.availability') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-white/10 bg-white/[0.06] px-4 text-sm font-semibold text-white transition hover:bg-white/[0.10] focus:outline-none focus:ring-2 focus:ring-indigo-400">Set availability</a>
                <a href="{{ route('dashboard.instructor.lessons') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-indigo-500 px-4 text-sm font-semibold text-white shadow-lg shadow-indigo-950/30 transition hover:bg-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-300">View lessons</a>
            </div>
        </div>
    </section>

    <section aria-labelledby="teaching-snapshot-title">
        <div class="mb-3 flex items-end justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-300">At a glance</p>
                <h2 id="teaching-snapshot-title" class="mt-1 text-lg font-semibold text-white">Teaching snapshot</h2>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-account.stat-card label="Upcoming" :value="(string) $upcomingCount" gradient="from-indigo-500 to-violet-500" icon="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            <x-account.stat-card label="Completed" :value="(string) $completedSessions" gradient="from-emerald-500 to-teal-500" icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            <x-account.stat-card label="Teaching hours" :value="number_format($teachingHours, 1)" gradient="from-amber-500 to-orange-500" icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            <x-account.stat-card label="Subjects" :value="(string) $subjectCount" gradient="from-pink-500 to-rose-500" icon="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(19rem,0.85fr)]">
        <x-account.card>
            <div class="mb-4 flex items-center justify-between gap-4">
                <div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-indigo-300">Schedule</p><h2 class="mt-1 text-lg font-semibold text-white">Next lessons</h2></div>
                <a href="{{ route('dashboard.instructor.lessons') }}" class="text-sm font-semibold text-indigo-300 hover:text-indigo-200">View all</a>
            </div>
            <div class="space-y-2">
                @forelse($nextClasses as $booking)
                    <article wire:key="instructor-next-class-{{ $booking['id'] }}" class="group grid grid-cols-[3.4rem_minmax(0,1fr)] gap-3 rounded-2xl border border-white/[0.06] bg-white/[0.025] p-3 transition hover:border-indigo-400/20 hover:bg-white/[0.04] sm:grid-cols-[4rem_minmax(0,1fr)_auto] sm:items-center">
                        <div class="rounded-xl bg-indigo-500/10 py-2 text-center">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-indigo-300">{{ $booking['starts_at']->format('M') }}</p>
                            <p class="text-xl font-bold text-white">{{ $booking['starts_at']->format('j') }}</p>
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-white">{{ $booking['subject'] }}</p>
                            <p class="mt-1 truncate text-xs text-slate-400">{{ $booking['student'] }} · {{ $booking['type'] }}</p>
                        </div>
                        <div class="col-start-2 text-left sm:col-auto sm:text-right">
                            <p class="text-sm font-semibold text-slate-200">{{ $booking['starts_at']->format('g:i A') }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $booking['ends_at']->format('g:i A') }}</p>
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-white/10 px-5 py-10 text-center">
                        <p class="font-semibold text-slate-200">Your schedule is clear</p>
                        <p class="mx-auto mt-2 max-w-sm text-sm text-slate-400">No upcoming lessons yet. Keep your availability current so students can find the right time.</p>
                        <a href="{{ route('dashboard.instructor.availability') }}" class="mt-4 inline-flex min-h-10 items-center rounded-xl bg-indigo-500/10 px-4 text-sm font-semibold text-indigo-300 hover:bg-indigo-500/20">Update availability</a>
                    </div>
                @endforelse
            </div>
        </x-account.card>

        <div class="space-y-6">
            <x-account.card>
                <div class="mb-4 flex items-center justify-between"><div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-300">Action needed</p><h2 class="mt-1 text-lg font-semibold text-white">Teaching queue</h2></div><span class="rounded-full bg-amber-400/10 px-2.5 py-1 text-xs font-bold text-amber-200">{{ $reviewDueLearningPlanCount + $pendingAssessmentLearningPlanCount + $homework['pending_reviews'] }}</span></div>
                <div class="space-y-2">
                    <a href="{{ route('dashboard.instructor.learning-plans') }}" class="flex items-center justify-between rounded-xl bg-white/[0.035] p-3 hover:bg-white/[0.06]"><span class="text-sm text-slate-300">Learning plan reviews</span><span class="font-bold text-white">{{ $reviewDueLearningPlanCount }}</span></a>
                    <a href="{{ route('dashboard.instructor.learning-plans') }}" class="flex items-center justify-between rounded-xl bg-white/[0.035] p-3 hover:bg-white/[0.06]"><span class="text-sm text-slate-300">Assessments to prepare</span><span class="font-bold text-white">{{ $pendingAssessmentLearningPlanCount }}</span></a>
                    <div class="flex items-center justify-between rounded-xl bg-white/[0.035] p-3"><span class="text-sm text-slate-300">Homework submissions</span><span class="font-bold text-white">{{ $homework['pending_reviews'] }}</span></div>
                    <a href="{{ route('dashboard.notifications') }}" class="flex items-center justify-between rounded-xl bg-white/[0.035] p-3 hover:bg-white/[0.06]"><span class="text-sm text-slate-300">Unread notifications</span><span class="font-bold text-white">{{ $unreadCount }}</span></a>
                </div>
            </x-account.card>

            <x-account.card>
                <div class="mb-4 flex items-center justify-between"><div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-300">Money</p><h2 class="mt-1 text-lg font-semibold text-white">Earnings</h2></div>@if($payoutsAvailable)<a href="{{ route('dashboard.instructor.payout-methods') }}" class="text-xs font-semibold text-indigo-300 hover:text-indigo-200">Payouts</a>@endif</div>
                @forelse($earnings as $earning)
                    <div class="{{ !$loop->first ? 'mt-4 border-t border-white/[0.06] pt-4' : '' }}">
                        <div class="flex items-end justify-between gap-3"><div><p class="text-xs text-slate-400">Available</p><p class="mt-1 text-2xl font-bold text-white">{{ $earning['available'] }}</p></div><span class="text-xs font-semibold text-slate-500">{{ $earning['currency'] }}</span></div>
                        <p class="mt-2 text-xs text-slate-400">{{ $earning['on_hold'] }} pending release</p>
                    </div>
                @empty
                    <p class="text-sm leading-6 text-slate-400">Earnings from completed eligible lessons will appear here after processing.</p>
                @endforelse
            </x-account.card>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-account.card>
            <div class="mb-4 flex items-center justify-between"><div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-violet-300">Student progress</p><h2 class="mt-1 text-lg font-semibold text-white">Learning plans</h2></div><a href="{{ route('dashboard.instructor.learning-plans') }}" class="text-sm font-semibold text-indigo-300 hover:text-indigo-200">Manage</a></div>
            {{-- Phase 23I: review-due/assessment counts live only in the
                 "Teaching queue" card above — this card no longer repeats them. --}}
            <div class="rounded-2xl bg-white/[0.035] p-4">
                <p class="text-2xl font-bold text-white">{{ $assignedActiveLearningPlanCount }}</p>
                <p class="mt-1 text-xs text-slate-400">Active learning {{ Str::plural('plan', $assignedActiveLearningPlanCount) }}</p>
            </div>
        </x-account.card>

        {{-- Phase 23I: visibility is decided by InstructorDashboardService::onboardingPromptVisible()
             — Approved/Active/Vacation/Suspended/Archived never see this card again,
             even if a completeness percentage is stale (e.g. an admin Force Approve). --}}
        @if($onboarding['show_prompt'] && $onboarding['variant'] === 'rejected')
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-rose-300">Application status</p>
                <h2 class="mt-1 text-lg font-semibold text-white">Application not approved</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">Your instructor application was not approved. Contact support if you have questions about this decision.</p>
                <a href="{{ route('forms.support') }}" class="mt-4 inline-flex min-h-10 items-center rounded-xl border border-white/10 px-4 text-sm font-semibold text-slate-200 hover:bg-white/[0.05]">Contact support</a>
            </x-account.card>
        @elseif($onboarding['show_prompt'])
            <x-account.card>
                <div class="flex items-start justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-sky-300">Profile readiness</p><h2 class="mt-1 text-lg font-semibold text-white">{{ $onboarding['status']?->label() ?? 'Complete instructor setup' }}</h2></div><span class="text-sm font-bold text-indigo-300">{{ $onboarding['percentage'] }}%</span></div>
                <div class="mt-4 h-2 overflow-hidden rounded-full bg-white/[0.06]" role="progressbar" aria-label="Instructor profile completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $onboarding['percentage'] }}"><div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-400" style="width: {{ $onboarding['percentage'] }}%"></div></div>
                @if($onboarding['missing'])<p class="mt-3 text-sm text-slate-400">Next: {{ implode(', ', array_slice($onboarding['missing'], 0, 2)) }}</p>@endif
                <div class="mt-4 flex gap-2"><a href="{{ route('dashboard.instructor.onboarding') }}" class="inline-flex min-h-10 flex-1 items-center justify-center rounded-xl border border-white/10 px-4 text-sm font-semibold text-slate-200 hover:bg-white/[0.05]">Continue setup</a>@if($onboarding['next_action'] === 'submit_application')<form method="POST" action="{{ route('dashboard.instructor.submit') }}" class="flex-1">@csrf<button type="submit" class="min-h-10 w-full rounded-xl bg-indigo-500 px-4 text-sm font-semibold text-white hover:bg-indigo-400">Submit for review</button></form>@endif</div>
            </x-account.card>
        @else
            <x-account.card>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sky-300">Workspace tip</p><h2 class="mt-1 text-lg font-semibold text-white">Keep your schedule bookable</h2><p class="mt-2 text-sm leading-6 text-slate-400">Review availability every week and block time you cannot teach to prevent scheduling conflicts.</p><a href="{{ route('dashboard.instructor.availability') }}" class="mt-4 inline-flex min-h-10 items-center rounded-xl border border-white/10 px-4 text-sm font-semibold text-slate-200 hover:bg-white/[0.05]">Review availability</a>
            </x-account.card>
        @endif
    </div>
</div>
