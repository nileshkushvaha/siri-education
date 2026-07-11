{{-- Agreement terms + admin decision context. Experience/qualifications
     inform the administrator's decision — they never calculate the
     amount. No student pricing appears here by design. --}}
<div class="space-y-6 text-sm">
    <div>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Agreement</h3>
        <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div><dt class="text-gray-500 dark:text-gray-400">Reference</dt><dd class="font-mono">{{ $agreement->reference }} (v{{ $agreement->version }})</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Status</dt><dd>{{ $agreement->status->label() }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Basis</dt><dd>{{ $agreement->pay_basis->label() }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Agreed amount</dt><dd class="font-semibold">{{ \App\Support\MoneyFormatter::format($agreement->amount_minor, $agreement->currency_code) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Effective</dt><dd>{{ $agreement->effective_from->format('M j, Y H:i') }} → {{ $agreement->effective_until?->format('M j, Y H:i') ?? 'open' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Timezone</dt><dd>{{ $agreement->timezone }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-gray-500 dark:text-gray-400">Internal reason (admin-only)</dt><dd>{{ $agreement->internal_reason }}</dd></div>
            @if ($agreement->notes)
                <div class="sm:col-span-2"><dt class="text-gray-500 dark:text-gray-400">Notes (admin-only)</dt><dd>{{ $agreement->notes }}</dd></div>
            @endif
        </dl>
    </div>

    @if ($agreement->overrides->isNotEmpty())
        <div>
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Hourly overrides</h3>
            <ul class="space-y-1">
                @foreach ($agreement->overrides as $override)
                    <li>
                        {{ $override->subject?->name ?? 'Any subject' }}
                        · {{ $override->academicLevel?->name ?? 'any level' }}
                        · {{ $override->duration_minutes ? $override->duration_minutes.' min' : 'any duration' }}
                        → <strong>{{ \App\Support\MoneyFormatter::format($override->amount_minor, $agreement->currency_code) }}</strong>/hour
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Decision context (informational only — never a formula)</h3>
        <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div><dt class="text-gray-500 dark:text-gray-400">Instructor</dt><dd>{{ $agreement->instructor?->name }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Experience</dt><dd>{{ $agreement->instructor?->profile?->instructor_teaching_experience_summary ?: '—' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Qualifications</dt><dd>{{ $agreement->instructor?->educations->count() ?? 0 }} education record(s)</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Subjects</dt><dd>{{ $agreement->instructor?->teacherSubjects->pluck('subject.name')->filter()->join(', ') ?: '—' }}</dd></div>
        </dl>
    </div>
</div>
