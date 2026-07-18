@php
    use App\Enums\InstructorStatus;

    $status = $progress['status'];
    $percentage = max(0, min(100, (int) $progress['percentage']));
    $missing = $progress['missing'];
    $isReviewable = $status !== null && in_array($status, InstructorStatus::needsReview(), true);
    $guidance = match ($status) {
        InstructorStatus::Submitted => 'Application submitted. Start the formal review, then verify the profile and required evidence.',
        InstructorStatus::UnderReview => 'Review in progress. Check professional details and evidence before choosing the next outcome.',
        InstructorStatus::DocumentsPending => 'Waiting for the instructor to provide the requested evidence before review can continue.',
        InstructorStatus::InterviewRequired => 'An interview is required. Record the outcome before approving or rejecting the application.',
        InstructorStatus::Approved => 'Approved and eligible for bookings. Activate when the operational checks are complete.',
        InstructorStatus::Active => 'Active in the instructor marketplace, subject to availability and public-profile visibility.',
        InstructorStatus::Rejected => 'Application rejected. Review the recorded reason before considering any future reapplication.',
        default => 'Review the application state and use only the lifecycle actions valid for this instructor.',
    };
@endphp

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="grid gap-6 bg-gradient-to-r from-indigo-600 via-violet-600 to-fuchsia-600 p-6 text-white xl:grid-cols-[1.15fr_.85fr]">
        <div class="flex min-w-0 items-start gap-4">
            <div class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-white/15 text-lg font-black ring-1 ring-white/25">
                {{ str($record->name)->explode(' ')->filter()->take(2)->map(fn ($part) => str($part)->substr(0, 1))->implode('') ?: 'IN' }}
            </div>
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-indigo-100">Instructor application review</p>
                <h2 class="mt-1 truncate text-2xl font-black tracking-tight">{{ $record->name }}</h2>
                <p class="mt-1 truncate text-sm text-indigo-100">{{ $record->email }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::badge :color="$status?->color() ?? 'gray'">{{ $status?->label() ?? 'Not started' }}</x-filament::badge>
                    <span class="inline-flex items-center rounded-md bg-white/10 px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ring-white/20">{{ $isReviewable ? 'Admin decision required' : 'Lifecycle status' }}</span>
                </div>
            </div>
        </div>

        <div class="rounded-xl bg-black/15 p-4 ring-1 ring-inset ring-white/15">
            <div class="flex items-center justify-between gap-4"><span class="text-sm font-bold">Application completeness</span><strong class="text-xl">{{ $percentage }}%</strong></div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-black/20"><div class="h-full rounded-full bg-gradient-to-r from-cyan-300 to-emerald-300" style="width: {{ $percentage }}%"></div></div>
            <p class="mt-3 text-xs leading-5 text-indigo-100">{{ $missing === [] ? 'All required application items are present.' : count($missing).' required '.str('item')->plural(count($missing)).' still missing.' }}</p>
        </div>
    </div>

    <div class="grid gap-5 p-5 lg:grid-cols-[1fr_auto] lg:items-center">
        <div><p class="text-sm font-bold text-gray-950 dark:text-white">Recommended review focus</p><p class="mt-1 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $guidance }}</p></div>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-xs">
            <div><dt class="font-semibold uppercase tracking-wide text-gray-500">Submitted</dt><dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $record->profile?->instructor_application_submitted_at?->format('d M Y, H:i') ?? 'Not submitted' }}</dd></div>
            <div><dt class="font-semibold uppercase tracking-wide text-gray-500">Last reviewed</dt><dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $record->profile?->instructor_reviewed_at?->format('d M Y, H:i') ?? 'Not reviewed' }}</dd></div>
        </dl>
    </div>

    @if($missing !== [])
        <div class="border-t border-amber-200 bg-amber-50 px-5 py-4 dark:border-amber-400/20 dark:bg-amber-400/5">
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700 dark:text-amber-300">Missing application items</p>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach($missing as $item)
                    <span class="rounded-md border border-amber-200 bg-white px-2 py-1 text-xs font-semibold text-amber-800 dark:border-amber-400/20 dark:bg-white/5 dark:text-amber-200">{{ str($item)->headline() }}</span>
                @endforeach
            </div>
        </div>
    @endif
</div>
