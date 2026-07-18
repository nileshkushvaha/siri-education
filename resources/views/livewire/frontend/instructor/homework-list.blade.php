<div>
    @if (session('success'))
        <div class="mb-4 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    <div class="mb-4 flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-amber-300">Pending Review</h2>
        <span class="rounded-full bg-amber-400/10 px-2.5 py-1 text-xs font-bold text-amber-200">{{ $pending->total() }}</span>
    </div>

    <div class="mb-8">
    <x-account.card>
        @forelse($pending as $assignment)
            <div wire:key="pending-{{ $assignment->id }}" class="py-4 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="text-sm font-medium text-white truncate">{{ $assignment->title }}</p>
                            <x-ui.badge :color="$assignment->status->color()">{{ $assignment->status->label() }}</x-ui.badge>
                        </div>
                        <p class="text-xs text-slate-400">{{ $assignment->subject }} &middot; {{ $assignment->student?->name ?? 'Student' }}</p>
                        <p class="text-xs text-slate-400 mt-1">Submitted {{ $assignment->submitted_at?->format('M j, Y g:i A') }}</p>
                    </div>

                    @if($reviewingId !== $assignment->id)
                        <button wire:click="startReview('{{ $assignment->id }}')"
                                class="flex-shrink-0 px-4 py-2 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all">
                            Review
                        </button>
                    @endif
                </div>

                @if($assignment->submission_text)
                    <p class="mt-3 rounded-xl bg-white/[0.03] px-3 py-2 text-xs leading-5 text-slate-300">{{ $assignment->submission_text }}</p>
                @endif

                @if($reviewingId === $assignment->id)
                    <div class="mt-4">
                        <textarea wire:model="feedbackText" rows="3"
                                  class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40"
                                  placeholder="Feedback for the student..."></textarea>
                        @error('feedbackText')
                            <p class="text-xs text-rose-400 mt-1">{{ $message }}</p>
                        @enderror

                        <input type="text" wire:model="grade"
                               class="mt-3 w-full max-w-[10rem] rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40"
                               placeholder="Grade (optional)" />
                        @error('grade')
                            <p class="text-xs text-rose-400 mt-1">{{ $message }}</p>
                        @enderror

                        <div class="flex items-center gap-2 mt-3">
                            <button wire:click="submitReview"
                                    class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all">
                                Mark Reviewed
                            </button>
                            <button wire:click="cancelReview"
                                    class="px-4 py-2 rounded-xl text-xs font-medium text-slate-400 hover:text-white transition-all">
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <x-ui.empty-state title="No submissions waiting for review" description="Once a student submits homework you've assigned, it will show up here." />
        @endforelse

        @if($pending->hasPages())
            <div class="mt-6 pt-4 border-t border-white/[0.04]">
                {{ $pending->links() }}
            </div>
        @endif
    </x-account.card>
    </div>

    <div class="mb-4">
        <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-emerald-300">Recently Reviewed</h2>
    </div>

    <x-account.card>
        @forelse($recentlyGraded as $assignment)
            <div wire:key="graded-{{ $assignment->id }}" class="py-4 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="flex items-center gap-2 mb-1">
                    <p class="text-sm font-medium text-white truncate">{{ $assignment->title }}</p>
                    <x-ui.badge :color="$assignment->status->color()">{{ $assignment->status->label() }}</x-ui.badge>
                </div>
                <p class="text-xs text-slate-400">{{ $assignment->subject }} &middot; {{ $assignment->student?->name ?? 'Student' }}</p>
                @if($assignment->grade)
                    <p class="text-xs text-emerald-400 mt-1">Grade: {{ $assignment->grade }}</p>
                @endif
                @if($assignment->feedback)
                    <p class="mt-2 rounded-xl bg-white/[0.03] px-3 py-2 text-xs leading-5 text-slate-300">{{ $assignment->feedback }}</p>
                @endif
            </div>
        @empty
            <x-ui.empty-state title="No reviewed homework yet" description="Homework you've reviewed and graded will appear here." />
        @endforelse
    </x-account.card>
</div>
