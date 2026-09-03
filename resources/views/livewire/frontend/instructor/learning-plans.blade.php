<div class="space-y-6">
    @if(session('success'))
        <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    <x-account.card title="Assigned Plans">
        @forelse($plans as $plan)
            <button type="button" wire:click="$set('selectedPlanId', {{ $plan->id }})"
                    class="w-full text-left py-4 {{ !$loop->last ? 'border-b border-edge' : '' }}">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-fg-strong">{{ $plan->title }}</p>
                        <p class="text-xs text-fg-faint mt-1">{{ $plan->student?->name }} · {{ $plan->subject?->name }}</p>
                    </div>
                    <span class="px-2 py-1 rounded-lg bg-surface-raised text-xs text-fg-muted">{{ $plan->status->label() }}</span>
                </div>
            </button>
        @empty
            <p class="py-8 text-sm text-fg-faint text-center">No assigned active learning plans yet.</p>
        @endforelse
    </x-account.card>

    @if($selectedPlan)
        <x-account.card title="Plan Workbench">
            <div class="mb-5">
                <p class="text-sm font-semibold text-fg-strong">{{ $selectedPlan->title }}</p>
                <p class="text-xs text-fg-faint mt-1">{{ $selectedPlan->progress_percent ?? 0 }}% progress · {{ $selectedPlan->milestones->count() }} milestones · {{ $selectedPlan->reviews->count() }} reviews</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <form wire:submit="recordAssessment" class="space-y-3">
                    <h3 class="text-sm font-semibold text-fg">Initial Assessment</h3>
                    <input type="text" wire:model="recommendedFocus" placeholder="Recommended focus"
                           class="w-full rounded-xl border border-edge bg-surface-raised px-4 py-3 text-fg-strong placeholder:text-fg-faint">
                    <textarea wire:model="assessmentNotes" rows="4" placeholder="Assessment notes"
                              class="w-full rounded-xl border border-edge bg-surface-raised px-4 py-3 text-fg-strong placeholder:text-fg-faint"></textarea>
                    <button class="px-4 py-2 rounded-lg bg-indigo-600 text-sm font-semibold text-white">Record</button>
                </form>

                <form wire:submit="createMilestone" class="space-y-3">
                    <h3 class="text-sm font-semibold text-fg">Milestone</h3>
                    <input type="text" wire:model="milestoneTitle" placeholder="Milestone title"
                           class="w-full rounded-xl border border-edge bg-surface-raised px-4 py-3 text-fg-strong placeholder:text-fg-faint">
                    <button class="px-4 py-2 rounded-lg bg-indigo-600 text-sm font-semibold text-white">Create</button>
                </form>

                <form wire:submit="createReview" class="space-y-3">
                    <h3 class="text-sm font-semibold text-fg">Progress Review</h3>
                    <textarea wire:model="reviewSummary" rows="4" placeholder="Review summary"
                              class="w-full rounded-xl border border-edge bg-surface-raised px-4 py-3 text-fg-strong placeholder:text-fg-faint"></textarea>
                    <div>
                        <label for="reviewProgressPercent" class="block text-xs text-fg-muted mb-1">Overall progress (%)</label>
                        <input type="number" id="reviewProgressPercent" wire:model="reviewProgressPercent" min="0" max="100" step="1"
                               placeholder="Leave blank if not assessed this review"
                               class="w-full rounded-xl border border-edge bg-surface-raised px-4 py-3 text-fg-strong placeholder:text-fg-faint">
                        <p class="mt-1 text-xs text-fg-faint">Your current structured assessment of the student's overall progress — leave blank for "not assessed," not 0.</p>
                        @error('reviewProgressPercent') <p class="mt-1 text-xs text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                    </div>
                    <button class="px-4 py-2 rounded-lg bg-indigo-600 text-sm font-semibold text-white">Create</button>
                </form>
            </div>
        </x-account.card>
    @endif
</div>
