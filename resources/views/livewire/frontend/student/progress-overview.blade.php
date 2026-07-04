<div>
    <div class="grid grid-cols-2 gap-4 mb-6">
        <x-account.stat-card label="Completed Sessions" :value="(string) ($stats->completed_sessions ?? 0)" gradient="from-emerald-500 to-teal-500"
            icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        <x-account.stat-card label="Hours Learned" :value="number_format((float) ($stats->total_hours ?? 0), 1)" gradient="from-amber-500 to-orange-500"
            icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
    </div>

    <x-account.card title="Subjects Studied">
        @forelse($subjects as $row)
            @php
                $max = $subjects->max('sessions') ?: 1;
                $percent = (int) round(($row->sessions / $max) * 100);
            @endphp
            <div wire:key="subject-{{ $row->subject }}" class="py-3 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-sm font-medium text-slate-200">{{ $row->subject }}</p>
                    <p class="text-xs text-slate-400">{{ $row->sessions }} {{ Str::plural('session', $row->sessions) }}</p>
                </div>
                <div class="h-1.5 rounded-full bg-white/[0.06] overflow-hidden">
                    <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500" style="width: {{ $percent }}%"></div>
                </div>
            </div>
        @empty
            <x-student.coming-soon
                icon="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                title="No progress yet"
                message="Complete a session to start tracking your subject progress." />
        @endforelse
    </x-account.card>
</div>
