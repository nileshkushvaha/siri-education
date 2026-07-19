<div>
    <div class="grid grid-cols-3 gap-4 mb-6">
        <x-account.stat-card label="Pending" :value="(string) ($stats->pending ?? 0)" gradient="from-amber-500 to-orange-500"
            icon="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        <x-account.stat-card label="Overdue" :value="(string) ($stats->overdue ?? 0)" gradient="from-rose-500 to-red-500"
            icon="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
        <x-account.stat-card label="Graded" :value="(string) ($stats->graded ?? 0)" gradient="from-emerald-500 to-teal-500"
            icon="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    <x-account.card>
        @forelse($assignments as $assignment)
            <div wire:key="homework-{{ $assignment->id }}" class="py-4 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="flex items-center justify-between">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="text-sm font-medium text-white truncate">{{ $assignment->title }}</p>
                            <x-ui.badge :color="$assignment->status->color()">{{ $assignment->status->label() }}</x-ui.badge>
                            @if($assignment->isOverdue())
                                <x-ui.badge color="danger">Overdue</x-ui.badge>
                            @endif
                        </div>
                        <p class="text-xs text-slate-400">{{ $assignment->subject }} &middot; with {{ $assignment->teacher?->name ?? 'Teacher' }}</p>
                        <x-homework.context-line :assignment="$assignment" />
                        <p class="text-xs text-slate-400 mt-1">Due {{ $assignment->due_at->format('M j, Y g:i A') }}</p>
                        @if($assignment->grade)
                            <p class="text-xs text-emerald-400 mt-1">Grade: {{ $assignment->grade }}</p>
                        @endif
                    </div>

                    @if($assignment->status === \App\Homework\Enums\HomeworkStatus::Pending && $submittingId !== $assignment->id)
                        <button wire:click="startSubmission('{{ $assignment->id }}')"
                                class="flex-shrink-0 ml-4 px-4 py-2 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all">
                            Submit
                        </button>
                    @endif
                </div>

                @if($submittingId === $assignment->id)
                    <div class="mt-4 pl-0">
                        <textarea wire:model="submissionText" rows="4"
                                  class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40"
                                  placeholder="Type your answer here..."></textarea>
                        @error('submissionText')
                            <p class="text-xs text-rose-400 mt-1">{{ $message }}</p>
                        @enderror
                        <div class="flex items-center gap-2 mt-3">
                            <button wire:click="submit"
                                    class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all">
                                Submit Homework
                            </button>
                            <button wire:click="cancelSubmission"
                                    class="px-4 py-2 rounded-xl text-xs font-medium text-slate-400 hover:text-white transition-all">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <h3 class="text-slate-300 font-semibold mb-2">No homework assigned</h3>
                <p class="text-slate-400 text-sm max-w-xs">Assignments from your teachers will appear here.</p>
            </div>
        @endforelse

        @if($assignments->hasPages())
            <div class="mt-6 pt-4 border-t border-white/[0.04]">
                {{ $assignments->links() }}
            </div>
        @endif
    </x-account.card>
</div>
