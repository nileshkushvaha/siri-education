<div class="space-y-6">
    @if(session('success'))
        <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    <x-account.card title="Active Learning Plans">
        @forelse($activePlans as $plan)
            <div wire:key="learning-plan-{{ $plan->id }}" class="py-4 {{ !$loop->last ? 'border-b border-white/[0.06]' : '' }}">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-white">{{ $plan->title }}</h3>
                            <span class="px-2 py-1 rounded-lg bg-indigo-500/10 text-xs text-indigo-200">{{ $plan->status->label() }}</span>
                            <span class="px-2 py-1 rounded-lg bg-white/[0.05] text-xs text-slate-300">{{ $plan->progress_percent ?? 0 }}%</span>
                        </div>
                        <p class="mt-2 text-sm text-slate-400">{{ $plan->subject?->name }}{{ $plan->academicLevel ? ' · '.$plan->academicLevel->name : '' }}</p>
                        <p class="mt-1 text-xs text-slate-500">Instructor: {{ $plan->primaryInstructor?->name ?? 'Not assigned yet' }}</p>
                        @if($plan->current_focus)
                            <p class="mt-3 text-sm text-slate-400">{{ $plan->current_focus }}</p>
                        @endif
                    </div>
                    <div class="text-sm text-slate-400">
                        <p>{{ $plan->milestones->where('status', \App\Enums\LearningPlanMilestoneStatus::Completed)->count() }} / {{ $plan->milestones->count() }} milestones</p>
                        <p>{{ $plan->reviews->count() }} reviews</p>
                    </div>
                </div>
            </div>
        @empty
            <div class="py-10 text-center">
                <p class="text-sm font-semibold text-slate-300">No learning plans yet.</p>
                <p class="text-sm text-slate-500 mt-1">Create a draft from an active learning goal when you are ready for instructor guidance.</p>
            </div>
        @endforelse
    </x-account.card>

    <x-account.card title="Start From Learning Goal">
        @forelse($availableGoals as $goal)
            <div wire:key="plan-goal-{{ $goal->id }}" class="flex items-center justify-between gap-4 py-3 {{ !$loop->last ? 'border-b border-white/[0.06]' : '' }}">
                <div>
                    <p class="text-sm font-medium text-white">{{ $goal->title }}</p>
                    <p class="text-xs text-slate-500 mt-1">{{ $goal->subject?->name }}</p>
                </div>
                <button type="button" wire:click="createFromGoal({{ $goal->id }})" class="px-3 py-2 rounded-lg bg-indigo-600 text-xs font-semibold text-white hover:bg-indigo-500">
                    Create Draft
                </button>
            </div>
        @empty
            <p class="py-6 text-sm text-slate-500">No eligible active learning goals are waiting for a plan.</p>
        @endforelse
    </x-account.card>

    <x-account.card title="Historical Plans">
        @forelse($historicalPlans as $plan)
            <div wire:key="historical-plan-{{ $plan->id }}" class="flex items-center justify-between py-3 {{ !$loop->last ? 'border-b border-white/[0.06]' : '' }}">
                <div>
                    <p class="text-sm font-medium text-slate-200">{{ $plan->title }}</p>
                    <p class="text-xs text-slate-500 mt-1">{{ $plan->subject?->name }} · {{ $plan->status->label() }}</p>
                </div>
                <span class="text-xs text-slate-500">{{ $plan->updated_at->format('M j, Y') }}</span>
            </div>
        @empty
            <p class="py-6 text-sm text-slate-500">Completed and archived plans will stay here.</p>
        @endforelse
    </x-account.card>
</div>
