<div class="space-y-6">
    @if(session('success'))
        <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    <x-account.card title="Assigned Plans">
        @forelse($plans as $plan)
            <button type="button" wire:click="$set('selectedPlanId', {{ $plan->id }})"
                    class="w-full text-left py-4 {{ !$loop->last ? 'border-b border-white/[0.06]' : '' }}">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-white">{{ $plan->title }}</p>
                        <p class="text-xs text-slate-500 mt-1">{{ $plan->student?->name }} · {{ $plan->subject?->name }}</p>
                    </div>
                    <span class="px-2 py-1 rounded-lg bg-white/[0.05] text-xs text-slate-300">{{ $plan->status->label() }}</span>
                </div>
            </button>
        @empty
            <p class="py-8 text-sm text-slate-500 text-center">No assigned active learning plans yet.</p>
        @endforelse
    </x-account.card>

    @if($selectedPlan)
        <x-account.card title="Plan Workbench">
            <div class="mb-5">
                <p class="text-sm font-semibold text-white">{{ $selectedPlan->title }}</p>
                <p class="text-xs text-slate-500 mt-1">{{ $selectedPlan->progress_percent ?? 0 }}% progress · {{ $selectedPlan->milestones->count() }} milestones · {{ $selectedPlan->reviews->count() }} reviews</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <form wire:submit="recordAssessment" class="space-y-3">
                    <h3 class="text-sm font-semibold text-slate-200">Initial Assessment</h3>
                    <input type="text" wire:model="recommendedFocus" placeholder="Recommended focus"
                           class="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-4 py-3 text-white placeholder:text-slate-500">
                    <textarea wire:model="assessmentNotes" rows="4" placeholder="Assessment notes"
                              class="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-4 py-3 text-white placeholder:text-slate-500"></textarea>
                    <button class="px-4 py-2 rounded-lg bg-indigo-600 text-sm font-semibold text-white">Record</button>
                </form>

                <form wire:submit="createMilestone" class="space-y-3">
                    <h3 class="text-sm font-semibold text-slate-200">Milestone</h3>
                    <input type="text" wire:model="milestoneTitle" placeholder="Milestone title"
                           class="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-4 py-3 text-white placeholder:text-slate-500">
                    <button class="px-4 py-2 rounded-lg bg-indigo-600 text-sm font-semibold text-white">Create</button>
                </form>

                <form wire:submit="createReview" class="space-y-3">
                    <h3 class="text-sm font-semibold text-slate-200">Progress Review</h3>
                    <textarea wire:model="reviewSummary" rows="4" placeholder="Review summary"
                              class="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-4 py-3 text-white placeholder:text-slate-500"></textarea>
                    <button class="px-4 py-2 rounded-lg bg-indigo-600 text-sm font-semibold text-white">Create</button>
                </form>
            </div>
        </x-account.card>
    @endif
</div>
