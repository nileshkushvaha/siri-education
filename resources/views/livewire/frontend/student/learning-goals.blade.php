<div class="space-y-6">
    @if(session('success'))
        <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    <x-account.card title="{{ $editingGoalId ? 'Edit Goal' : 'Create Goal' }}">
        <form wire:submit="save" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
                <label for="goal-title" class="block text-sm font-medium text-slate-200 mb-2">Goal title</label>
                <input id="goal-title" type="text" wire:model="title" placeholder="Improve algebra confidence before finals"
                       class="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-4 py-3 text-white placeholder:text-slate-500 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30">
                @error('title') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="goal-type" class="block text-sm font-medium text-slate-200 mb-2">Goal type</label>
                <select id="goal-type" wire:model.live="type"
                        class="w-full rounded-xl border border-white/[0.10] bg-slate-950 px-4 py-3 text-white focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30">
                    @foreach($types as $goalType)
                        <option value="{{ $goalType->value }}">{{ $goalType->label() }}</option>
                    @endforeach
                </select>
                <p class="mt-2 text-xs text-slate-500">Academic and exam goals need a level; other goals can skip it.</p>
                @error('type') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="goal-subject" class="block text-sm font-medium text-slate-200 mb-2">Subject</label>
                <select id="goal-subject" wire:model="subjectId"
                        class="w-full rounded-xl border border-white/[0.10] bg-slate-950 px-4 py-3 text-white focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30">
                    <option value="">Choose subject</option>
                    @foreach($subjects as $subject)
                        <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                    @endforeach
                </select>
                @error('subjectId') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="goal-level" class="block text-sm font-medium text-slate-200 mb-2">Academic level</label>
                <select id="goal-level" wire:model="academicLevelId"
                        class="w-full rounded-xl border border-white/[0.10] bg-slate-950 px-4 py-3 text-white focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30">
                    <option value="">Choose level when needed</option>
                    @foreach($academicLevels as $level)
                        <option value="{{ $level->id }}">{{ $level->name }}</option>
                    @endforeach
                </select>
                @error('academicLevelId') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="goal-target" class="block text-sm font-medium text-slate-200 mb-2">Target date</label>
                <input id="goal-target" type="date" wire:model="targetDate"
                       class="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-4 py-3 text-white focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30">
                @error('targetDate') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="goal-priority" class="block text-sm font-medium text-slate-200 mb-2">Priority</label>
                <select id="goal-priority" wire:model="priority"
                        class="w-full rounded-xl border border-white/[0.10] bg-slate-950 px-4 py-3 text-white focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30">
                    <option value="">No priority</option>
                    <option value="1">1 - Low</option>
                    <option value="2">2</option>
                    <option value="3">3 - Medium</option>
                    <option value="4">4</option>
                    <option value="5">5 - High</option>
                </select>
                @error('priority') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>

            <div class="md:col-span-2">
                <label for="goal-description" class="block text-sm font-medium text-slate-200 mb-2">Description</label>
                <textarea id="goal-description" wire:model="description" rows="4" placeholder="Add context, exam dates, weak areas, or what success should look like."
                          class="w-full rounded-xl border border-white/[0.10] bg-white/[0.04] px-4 py-3 text-white placeholder:text-slate-500 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/30"></textarea>
                @error('description') <p class="mt-2 text-xs text-rose-300">{{ $message }}</p> @enderror
            </div>

            <div class="md:col-span-2 flex flex-wrap gap-3">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500 transition">
                    {{ $editingGoalId ? 'Save Goal' : 'Create Goal' }}
                </button>
                @if($editingGoalId)
                    <button type="button" wire:click="resetForm" class="px-5 py-2.5 rounded-xl border border-white/[0.10] text-slate-300 text-sm font-semibold hover:text-white hover:bg-white/[0.05] transition">
                        Cancel
                    </button>
                @endif
            </div>
        </form>
    </x-account.card>

    <x-account.card title="Active Goals">
        @forelse($activeGoals as $goal)
            <div wire:key="active-goal-{{ $goal->id }}" class="py-4 {{ !$loop->last ? 'border-b border-white/[0.06]' : '' }}">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-semibold text-white">{{ $goal->title }}</h3>
                            <span class="px-2 py-1 rounded-lg bg-indigo-500/10 text-xs text-indigo-200">{{ $goal->type->label() }}</span>
                            <span class="px-2 py-1 rounded-lg bg-white/[0.05] text-xs text-slate-300">{{ $goal->status->label() }}</span>
                        </div>
                        <p class="mt-2 text-sm text-slate-400">{{ $goal->subject?->name }}{{ $goal->academicLevel ? ' · '.$goal->academicLevel->name : '' }}</p>
                        @if($goal->description)
                            <p class="mt-2 text-sm text-slate-500 max-w-2xl">{{ $goal->description }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="edit({{ $goal->id }})" class="px-3 py-2 rounded-lg border border-white/[0.10] text-xs font-semibold text-slate-300 hover:text-white hover:bg-white/[0.05]">Edit</button>
                        <button type="button" wire:click="complete({{ $goal->id }})" class="px-3 py-2 rounded-lg border border-emerald-500/20 text-xs font-semibold text-emerald-200 hover:bg-emerald-500/10">Complete</button>
                        <button type="button" wire:click="archive({{ $goal->id }})" class="px-3 py-2 rounded-lg border border-white/[0.10] text-xs font-semibold text-slate-400 hover:text-white hover:bg-white/[0.05]">Archive</button>
                    </div>
                </div>
            </div>
        @empty
            <div class="py-10 text-center">
                <p class="text-sm font-semibold text-slate-300">No active learning goals yet.</p>
                <p class="text-sm text-slate-500 mt-1">Create one goal above to guide instructor matching and your dashboard next steps.</p>
            </div>
        @endforelse
    </x-account.card>

    <x-account.card title="Completed & Archived">
        @forelse($historicalGoals as $goal)
            <div wire:key="historical-goal-{{ $goal->id }}" class="flex items-center justify-between py-3 {{ !$loop->last ? 'border-b border-white/[0.06]' : '' }}">
                <div>
                    <p class="text-sm font-medium text-slate-200">{{ $goal->title }}</p>
                    <p class="text-xs text-slate-500 mt-1">{{ $goal->subject?->name }} · {{ $goal->status->label() }}</p>
                </div>
                <span class="text-xs text-slate-500">{{ $goal->updated_at->format('M j, Y') }}</span>
            </div>
        @empty
            <p class="py-6 text-sm text-slate-500">Completed and archived goals will stay here for history.</p>
        @endforelse
    </x-account.card>
</div>
