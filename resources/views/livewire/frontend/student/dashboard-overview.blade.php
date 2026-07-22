<div class="space-y-6">
    @if($dashboard->nextLesson)
        @php($lesson = $dashboard->nextLesson)
        <section class="relative overflow-hidden rounded-3xl border border-indigo-400/20 bg-gradient-to-br from-indigo-500/15 via-slate-900 to-violet-500/10 p-6 md:p-8">
            <div class="absolute -right-12 -top-16 h-48 w-48 rounded-full bg-indigo-500/15 blur-3xl"></div>
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[.18em] text-indigo-300">Your next lesson</p>
                    <h2 class="mt-3 text-2xl font-bold text-white md:text-3xl">{{ $lesson['subject'] }}</h2>
                    <p class="mt-2 text-sm text-slate-300">with {{ $lesson['instructor'] }} · {{ $lesson['type'] }}</p>
                    <p class="mt-4 text-base font-semibold text-white">{{ $lesson['starts_at']->format('l, M j · g:i A') }}</p>
                    <p class="mt-1 text-xs text-slate-400" role="status">Meeting: {{ $lesson['meeting_status'] }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($lesson['join_url'])
                        <a href="{{ $lesson['join_url'] }}" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-indigo-500 px-5 py-3 text-sm font-bold text-white hover:bg-indigo-400">Join Class</a>
                    @elseif($lesson['meeting_status'] === 'Created' && !$lesson['join_window_open'])
                        <span class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-300">Join link available near lesson time</span>
                    @endif
                    <a href="{{ route('dashboard.my-bookings') }}" class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-white hover:bg-white/10">View Booking</a>
                    @if($lesson['can_reschedule'])<a href="{{ route('dashboard.my-bookings') }}" class="inline-flex min-h-11 items-center rounded-xl px-3 py-3 text-sm font-semibold text-indigo-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">Reschedule</a>@endif
                    @if($lesson['can_cancel'])<a href="{{ route('dashboard.my-bookings') }}" class="inline-flex min-h-11 items-center rounded-xl px-3 py-3 text-sm font-semibold text-rose-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-300">Cancel</a>@endif
                </div>
            </div>
        </section>
    @else
        <section class="rounded-3xl border border-indigo-400/20 bg-gradient-to-r from-indigo-500/15 to-violet-500/10 p-7 md:flex md:items-center md:justify-between">
            <div><p class="text-xs font-semibold uppercase tracking-[.18em] text-indigo-300">Ready when you are</p><h2 class="mt-2 text-2xl font-bold text-white">Plan your next learning session</h2><p class="mt-2 text-sm text-slate-400">Choose a tutor and a time that works for you.</p></div>
            <a href="{{ route('booking.create') }}" class="mt-5 inline-flex rounded-xl bg-indigo-500 px-5 py-3 text-sm font-bold text-white hover:bg-indigo-400 md:mt-0">Book a Class</a>
        </section>
    @endif

    @if($dashboard->homework)
        @php($homework = $dashboard->homework)
        <section class="rounded-2xl border border-white/[.08] bg-white/[.025] p-5 md:p-6">
            <div class="flex items-center justify-between gap-4"><div><h2 class="text-lg font-bold text-white">Homework requiring attention</h2><p class="mt-1 text-sm text-slate-400"><span class="text-rose-300">{{ $homework['overdue'] }} overdue</span> · {{ $homework['pending'] }} upcoming</p></div><a href="{{ route('dashboard.homework') }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-indigo-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">View all →</a></div>
            <div class="mt-4 divide-y divide-white/[.06]">
                @forelse($homework['items'] as $item)
                    <a href="{{ route('dashboard.homework') }}" class="flex items-center justify-between gap-4 py-4 hover:bg-white/[.02]">
                        <div class="min-w-0"><p class="truncate text-sm font-semibold text-white">{{ $item['title'] }}</p><p class="mt-1 text-xs text-slate-400">{{ $item['subject'] }}</p></div>
                        <div class="text-right"><span class="rounded-full px-2 py-1 text-xs font-semibold {{ $item['overdue'] ? 'bg-rose-500/10 text-rose-300' : 'bg-amber-500/10 text-amber-300' }}">{{ $item['overdue'] ? 'Overdue' : $item['status'] }}</span><p class="mt-2 text-xs text-slate-400">Due {{ $item['due_at']->format('M j, g:i A') }}</p></div>
                    </a>
                @empty<p class="py-6 text-sm text-slate-400" role="status">You’re all caught up.</p>@endforelse
            </div>
        </section>
    @endif

    <section class="rounded-2xl border border-white/[.08] bg-white/[.025] p-5 md:p-6">
        <div class="flex items-center justify-between"><h2 class="text-lg font-bold text-white">Learning journey</h2><a href="{{ route('dashboard.learning-plans') }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-indigo-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">View plan →</a></div>
        @if($dashboard->learningJourney)
            @php($journey = $dashboard->learningJourney)
            <div class="mt-5 grid gap-6 lg:grid-cols-[1fr_auto]">
                <div>
                    <p class="text-xl font-bold text-white">{{ $journey['title'] }}</p>
                    <p class="mt-1 text-sm text-slate-400">
                        {{ $journey['subject'] ?: 'Subject not specified' }}
                        @if($journey['instructor'])
                            · {{ $journey['instructor'] }}
                        @else
                            · Instructor not assigned
                        @endif
                    </p>
                    @if($journey['goal'])
                        <p class="mt-4 text-sm text-slate-300"><span class="text-slate-500">Goal:</span> {{ $journey['goal'] }}</p>
                    @else
                        <p class="mt-4 text-sm text-slate-400">No current goal is linked to this plan.</p>
                    @endif
                </div>
                <div class="lg:w-64"><div class="flex justify-between text-sm"><span class="text-slate-400">Progress</span><strong class="text-white">{{ $journey['progress'] }}%</strong></div><div class="mt-2 h-2 overflow-hidden rounded-full bg-white/[.07]" role="progressbar" aria-label="Learning plan progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $journey['progress'] }}"><div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-400" style="width: {{ $journey['progress'] }}%"></div></div><p class="mt-2 text-xs text-slate-400">{{ $journey['completed_milestones'] }} of {{ $journey['total_milestones'] }} milestones complete</p></div>
            </div>
            <div class="mt-5 grid gap-3 md:grid-cols-2">@if($journey['next_milestone'])<div class="rounded-xl bg-white/[.035] p-4"><p class="text-xs uppercase tracking-wide text-slate-500">Next milestone</p><p class="mt-2 text-sm font-semibold text-white">{{ $journey['next_milestone'] }}</p></div>@endif @if($journey['last_review_at'])<div class="rounded-xl bg-white/[.035] p-4"><p class="text-xs uppercase tracking-wide text-slate-500">Latest progress review</p><p class="mt-2 text-sm text-slate-300">{{ $journey['last_review'] ?: 'Review completed' }}</p>@if(! is_null($journey['last_review_progress_percent']))<p class="mt-2 text-sm font-semibold text-white">{{ $journey['last_review_progress_percent'] }}% overall progress assessed</p>@endif<p class="mt-2 text-xs text-slate-500">{{ $journey['last_review_at']->format('M j, Y') }}</p></div>@endif</div>
        @else<p class="mt-4 text-sm text-slate-400">Your active learning plan will appear here once one is created.</p>@endif
    </section>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @if($dashboard->wallet)<a href="{{ route('dashboard.wallet') }}" class="rounded-2xl border border-white/[.08] bg-white/[.025] p-5 hover:bg-white/[.045]"><p class="text-sm text-slate-400">Wallet</p><p class="mt-2 text-xl font-bold text-emerald-300">{{ $dashboard->wallet['available'] }}</p><p class="mt-2 truncate text-xs text-slate-500">{{ $dashboard->wallet['latest'] ?: 'No recent wallet activity' }}</p></a>@endif
        @if($dashboard->referral)<a href="{{ route('dashboard.refer-a-friend') }}" class="rounded-2xl border border-white/[.08] bg-white/[.025] p-5 hover:bg-white/[.045]"><p class="text-sm text-slate-400">Referrals</p><p class="mt-2 text-xl font-bold text-white">{{ $dashboard->referral['credited_count'] }} rewards</p><p class="mt-2 text-xs text-slate-500">{{ $dashboard->referral['code'] ? 'Code: '.$dashboard->referral['code'] : 'View referral program' }}</p></a>@endif
        <a href="{{ route('dashboard.wishlist') }}" class="rounded-2xl border border-white/[.08] bg-white/[.025] p-5 hover:bg-white/[.045]"><p class="text-sm text-slate-400">Favorite tutors</p><p class="mt-2 text-xl font-bold text-white">{{ count($dashboard->favorites ?? []) }} bookable</p><p class="mt-2 truncate text-xs text-slate-500">{{ collect($dashboard->favorites ?? [])->pluck('name')->join(', ') ?: 'Browse your favorites' }}</p></a>
        @if($dashboard->notifications)<a href="{{ route('dashboard.notifications') }}" class="rounded-2xl border border-white/[.08] bg-white/[.025] p-5 hover:bg-white/[.045]"><p class="text-sm text-slate-400">Notifications</p><p class="mt-2 text-xl font-bold text-white">{{ $dashboard->notifications['unread'] }} unread</p><p class="mt-2 truncate text-xs text-slate-500">{{ $dashboard->notifications['items'][0]['title'] ?? 'You’re up to date' }}</p></a>@endif
    </div>

    @if($dashboard->profile && $dashboard->profile['completion'] < 100)
        <div class="flex flex-col gap-3 rounded-2xl border border-amber-400/15 bg-amber-500/[.05] p-5 sm:flex-row sm:items-center sm:justify-between"><div><p class="text-sm font-semibold text-white">Complete your profile · {{ $dashboard->profile['completion'] }}%</p><p class="mt-1 text-xs text-slate-400">Add {{ implode(', ', array_slice($dashboard->profile['missing'], 0, 2)) }} to get more relevant learning options.</p></div><a href="{{ route('profile.show') }}" class="inline-flex min-h-11 items-center text-sm font-semibold text-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300">Complete profile →</a></div>
    @endif

    @if($dashboard->errors)<p class="text-center text-xs text-slate-400" role="status">Some dashboard information is temporarily unavailable. Your learning data is safe.</p>@endif
</div>
