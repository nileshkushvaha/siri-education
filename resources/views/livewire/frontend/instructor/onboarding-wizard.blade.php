@php
    $steps = [
        1 => ['label' => 'Overview', 'short' => 'Overview'],
        2 => ['label' => 'Professional Profile', 'short' => 'Profile'],
        3 => ['label' => 'Teaching Preferences', 'short' => 'Teaching'],
        4 => ['label' => 'Education', 'short' => 'Education'],
        5 => ['label' => 'Experience', 'short' => 'Experience'],
        6 => ['label' => 'Verification Documents', 'short' => 'Documents'],
        7 => ['label' => 'Review & Submit', 'short' => 'Review'],
    ];

    // Per-step state from the structured checklist: a middle step is
    // complete once every item it satisfies is done; Overview and Review
    // are never "complete" on their own.
    $itemsByStep = collect($progress['items'] ?? [])->groupBy('step');
    $stepState = function (int $number) use ($step, $itemsByStep): string {
        if ($number === $step) {
            return 'current';
        }

        $items = $itemsByStep->get($number);

        return $number >= 2 && $number <= 6 && $items !== null && $items->isNotEmpty() && $items->every(fn (array $item): bool => $item['done'])
            ? 'complete'
            : 'upcoming';
    };
    $itemsLeft = count($progress['missing']);

    $inputClass = 'w-full rounded-lg border border-edge bg-surface-raised px-4 py-3 text-sm text-fg-strong outline-none transition placeholder:text-fg-faint focus:border-indigo-400 focus:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-60';
    $selectClass = 'w-full cursor-pointer rounded-lg border border-edge bg-surface-solid px-4 py-3 text-sm text-fg-strong outline-none transition focus:border-indigo-400 disabled:cursor-not-allowed disabled:opacity-60';
    $buttonClass = 'inline-flex cursor-pointer items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50';
    $secondaryButtonClass = 'inline-flex cursor-pointer items-center justify-center rounded-lg border border-edge px-5 py-3 text-sm font-semibold text-fg transition hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-50';
    $helpClass = 'mt-2 text-xs leading-5 text-fg-faint';
    $errorClass = 'mt-2 text-xs font-medium text-rose-600 dark:text-rose-300';
    $dropdownButtonClass = 'flex w-full cursor-pointer items-center justify-between gap-3 rounded-lg border border-edge bg-surface-raised px-4 py-3 text-left text-sm text-fg-strong outline-none transition hover:bg-surface-hover focus:border-indigo-400 disabled:cursor-not-allowed disabled:opacity-60';

    $selectedSummary = function (array $options, array $selected, string $placeholder): string {
        $selectedIds = array_map('strval', $selected);
        $names = collect($options)
            ->filter(fn (array $option): bool => in_array((string) $option['id'], $selectedIds, true))
            ->pluck('name')
            ->values();

        if ($names->isEmpty()) {
            return $placeholder;
        }

        if ($names->count() <= 2) {
            return $names->join(', ');
        }

        return $names->take(2)->join(', ') . ' +' . ($names->count() - 2) . ' more';
    };
@endphp

{{-- Saving a section advances the wizard; bring the new step into view
     rather than leaving the instructor scrolled at the old form's footer. --}}
<div class="space-y-6"
     x-data
     @onboarding-step-changed.window="$el.scrollIntoView({ behavior: 'smooth', block: 'start' })">
    <div class="rounded-2xl border border-edge bg-surface-raised p-5 sm:p-6">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-3xl font-bold tracking-normal text-fg-strong sm:text-4xl">Instructor Onboarding</h1>
                    <span class="rounded-full border border-indigo-400/25 bg-indigo-500/10 px-3 py-1 text-xs font-semibold text-indigo-700 dark:text-indigo-200">
                        {{ $progress['status']?->label() ?? 'Not started' }}
                    </span>
                </div>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-fg-muted">
                    Complete the sections below so the team can review your instructor application.
                </p>
            </div>

            <div class="w-full lg:w-80">
                <div class="mb-2 flex items-center justify-between text-sm">
                    <span class="text-fg-muted">Application progress</span>
                    <span class="font-semibold text-indigo-600 dark:text-indigo-300">{{ $progress['percentage'] }}%</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-surface-raised">
                    <div class="h-full rounded-full bg-indigo-500" style="width: {{ $progress['percentage'] }}%"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-edge bg-surface-solid/40 p-2">
        <div class="flex gap-2 overflow-x-auto pb-1">
            @foreach($steps as $number => $stepMeta)
                @php $state = $stepState($number); @endphp
                <button type="button"
                        wire:click="$set('step', {{ $number }})"
                        data-state="{{ $state }}"
                        @if($state === 'current') aria-current="step" @endif
                        class="group flex min-w-[8.75rem] cursor-pointer items-center gap-3 rounded-xl px-3 py-3 text-left transition {{ $state === 'current' ? 'bg-indigo-500/15 text-fg-strong ring-1 ring-indigo-400/30' : 'text-fg-muted hover:bg-surface-hover hover:text-fg-strong' }}">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ match ($state) { 'current' => 'bg-indigo-500 text-white', 'complete' => 'bg-emerald-500 text-white', default => 'bg-surface-raised text-fg-muted group-hover:bg-surface-hover' } }}">
                        @if($state === 'complete')
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        @else
                            {{ $number }}
                        @endif
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold">{{ $stepMeta['short'] }}</span>
                        <span class="mt-0.5 block text-xs {{ $state === 'complete' ? 'text-emerald-600 dark:text-emerald-300' : 'text-fg-faint' }}">{{ match ($state) { 'current' => 'Current step', 'complete' => 'Done', default => 'Step '.$number } }}</span>
                    </span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="min-w-0">
        @if (session('success'))
            <div class="mb-4 rounded-xl border border-emerald-400/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-200">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-4 rounded-xl border border-rose-400/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-200">
                {{ session('error') }}
            </div>
        @endif

        @error('application')
            <div class="mb-4 rounded-xl border border-rose-400/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-200">{{ $message }}</div>
        @enderror

        @if($errors->any())
            <div class="mb-4 rounded-xl border border-rose-400/20 bg-rose-500/10 px-4 py-4 text-sm text-rose-100">
                <p class="font-semibold">Please fix the highlighted fields.</p>
                <ul class="mt-2 list-inside list-disc space-y-1 text-rose-700 dark:text-rose-200">
                    @foreach($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(! $editable && $step !== 1 && $step !== 7)
            <div class="mb-4 rounded-xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                This application is locked while it is being reviewed.
            </div>
        @endif

        <section class="rounded-2xl border border-edge bg-surface-raised p-5 sm:p-6 xl:p-8">
            <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-300">Step {{ $step }} of {{ count($steps) }}</p>
                    <h2 class="mt-1 text-2xl font-bold text-fg-strong">{{ $steps[$step]['label'] }}</h2>
                </div>

                @if($progress['missing'])
                    <span class="w-fit rounded-full bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-700 dark:text-amber-200">
                        {{ count($progress['missing']) }} item{{ count($progress['missing']) === 1 ? '' : 's' }} left
                    </span>
                @else
                    <span class="w-fit rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-200">Ready</span>
                @endif
            </div>

            @if($step === 1)
                <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_22rem]">
                    <div class="rounded-xl border border-edge bg-surface-solid/35 p-5" data-application-checklist>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-lg font-semibold text-fg-strong">Application checklist</h3>
                            <p class="text-sm text-fg-muted">
                                @if($itemsLeft === 0)
                                    Everything required is complete.
                                @else
                                    Each section below has its own step. Select a section to go there.
                                @endif
                            </p>
                        </div>

                        <div class="mt-4 space-y-4">
                            @foreach($steps as $number => $stepMeta)
                                @php $group = $itemsByStep->get($number); @endphp
                                @continue($group === null || $group->isEmpty())
                                @php $groupDone = $group->every(fn (array $item): bool => $item['done']); @endphp
                                <div class="rounded-xl border {{ $groupDone ? 'border-emerald-400/30 bg-emerald-500/5' : 'border-edge bg-surface-raised' }} p-4" data-checklist-step="{{ $number }}">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div class="flex items-center gap-2">
                                            <span class="flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold {{ $groupDone ? 'bg-emerald-500 text-white' : 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-200' }}">{{ $number }}</span>
                                            <h4 class="text-sm font-semibold text-fg-strong">{{ $stepMeta['label'] }}</h4>
                                            <span class="text-xs text-fg-faint">{{ $group->where('done', true)->count() }} of {{ $group->count() }} done</span>
                                        </div>
                                        <button type="button" wire:click="$set('step', {{ $number }})" class="inline-flex min-h-9 cursor-pointer items-center gap-1 rounded-lg px-3 text-xs font-semibold {{ $groupDone ? 'text-fg-muted hover:text-fg-strong' : 'text-indigo-600 hover:text-indigo-500 dark:text-indigo-300' }}">
                                            {{ $groupDone ? 'Review' : 'Go to step' }} <span aria-hidden="true">→</span>
                                        </button>
                                    </div>
                                    <ul class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                                        @foreach($group as $item)
                                            <li class="flex items-center gap-2 text-sm {{ $item['done'] ? 'text-fg-muted' : 'text-fg-strong' }}" data-checklist-item="{{ $item['key'] }}" data-done="{{ $item['done'] ? 'true' : 'false' }}">
                                                @if($item['done'])
                                                    <svg class="h-4 w-4 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                                    <span class="sr-only">Done:</span>
                                                @else
                                                    <span class="h-4 w-4 shrink-0 rounded-full border-2 border-edge-strong" aria-hidden="true"></span>
                                                    <span class="sr-only">To do:</span>
                                                @endif
                                                {{ $item['label'] }}
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-xl border border-edge bg-surface-solid/35 p-5" data-next-action="{{ $progress['status'] ? 'continue' : ($eligibility['eligible'] ? 'start' : $eligibility['code']) }}">
                        <h3 class="text-lg font-semibold text-fg-strong">Next action</h3>

                        @if(! $progress['status'])
                            @if($eligibility['eligible'])
                                <p class="mt-2 text-sm leading-6 text-fg-muted">Start your application, then complete each section at your own pace. Your progress is saved as you go.</p>
                                <div class="mt-5">
                                    <button type="button" wire:click="start" class="{{ $buttonClass }} w-full">Start Onboarding</button>
                                </div>
                            @elseif($eligibility['code'] === 'missing_education_information')
                                <p class="mt-2 text-sm leading-6 text-fg-muted">First, tell us about your education. We need at least one qualification on file before an application can be opened — saving it starts your application automatically.</p>
                                <div class="mt-5">
                                    <button type="button" wire:click="goToEducation" class="{{ $buttonClass }} w-full">Add my education</button>
                                </div>
                            @elseif($eligibility['code'] === 'email_not_verified')
                                <p class="mt-2 text-sm leading-6 text-fg-muted">{{ $eligibility['reason'] }}</p>
                                <div class="mt-5">
                                    <a href="{{ route('auth.verification.notice') }}" class="{{ $buttonClass }} w-full">Verify my email</a>
                                </div>
                            @else
                                <p class="mt-2 text-sm leading-6 text-rose-700 dark:text-rose-300" role="status">{{ $eligibility['reason'] }}</p>
                                <p class="mt-3 text-sm leading-6 text-fg-muted">Applications cannot be opened for this account at the moment. If you think this is a mistake, contact support.</p>
                            @endif
                        @else
                            @php $nextStep = $progress['first_incomplete_step'] ?? 7; @endphp
                            <p class="mt-2 text-sm leading-6 text-fg-muted">
                                @if($progress['next_action'] === 'submit_application')
                                    Every required item is complete. Review your application and submit it.
                                @else
                                    Pick up where you left off. Next: <span class="font-semibold text-fg-strong">{{ $steps[$nextStep]['label'] }}</span>.
                                @endif
                            </p>
                            <div class="mt-5 flex flex-col gap-3">
                                @if($progress['next_action'] === 'submit_application')
                                    <button type="button" wire:click="$set('step', 7)" class="inline-flex cursor-pointer items-center justify-center rounded-lg bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-500">
                                        Review & Submit
                                    </button>
                                @else
                                    <button type="button" wire:click="continueApplication" class="{{ $buttonClass }}">Continue application</button>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if($step === 2)
                <form wire:submit="saveProfile" class="space-y-6">
                    <p class="max-w-3xl text-sm leading-6 text-fg-muted">
                        This information appears in your instructor profile after approval. Keep it specific to what you teach and how you help students.
                    </p>

                    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                        <div class="lg:col-span-2">
                            <label class="mb-2 block text-sm font-medium text-fg">Headline</label>
                            <input type="text" wire:model="profile.headline" @disabled(! $editable) placeholder="Example: AP Physics and SAT Math instructor with 6 years of classroom experience" class="{{ $inputClass }}">
                            <p class="{{ $helpClass }}">Use one clear sentence that tells students your subject focus and credibility.</p>
                            @error('profile.headline') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                        </div>

                        <div class="lg:col-span-2">
                            <label class="mb-2 block text-sm font-medium text-fg">Biography</label>
                            <textarea wire:model="profile.bio" rows="4" @disabled(! $editable) placeholder="Share your teaching background, the students you usually support, and what learners can expect in your sessions." class="{{ $inputClass }}"></textarea>
                            <p class="{{ $helpClass }}">Write 2-4 short paragraphs. Avoid phone numbers, emails, or external links.</p>
                            @error('profile.bio') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium text-fg">Teaching Experience Summary</label>
                            <textarea wire:model="profile.teaching_experience_summary" rows="5" @disabled(! $editable) placeholder="Example: I have taught Grade 9-12 physics, prepared students for AP exams, and designed lab-based problem-solving lessons." class="{{ $inputClass }}"></textarea>
                            <p class="{{ $helpClass }}">Mention years, grade bands, exams, classroom/online experience, and measurable outcomes if available.</p>
                            @error('profile.teaching_experience_summary') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium text-fg">Teaching Philosophy</label>
                            <textarea wire:model="profile.teaching_philosophy" rows="5" @disabled(! $editable) placeholder="Example: I start with diagnostic questions, build intuition with examples, then move into structured practice and feedback." class="{{ $inputClass }}"></textarea>
                            <p class="{{ $helpClass }}">Describe how you diagnose gaps, explain concepts, practice skills, and keep students engaged.</p>
                            @error('profile.teaching_philosophy') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4">
                        <div class="rounded-xl border border-edge bg-surface-solid/35 p-4">
                            <label class="mb-3 block text-sm font-medium text-fg">Profile Photo</label>
                            <input type="file" wire:model="profilePhoto" @disabled(! $editable) class="block w-full cursor-pointer text-sm text-fg-muted file:mr-4 file:cursor-pointer file:rounded-lg file:border-0 file:bg-indigo-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white file:transition hover:file:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60">
                            <p class="{{ $helpClass }}">Upload a clear headshot. JPG or PNG works best, up to 4 MB.</p>
                            @error('profilePhoto') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <button type="submit" @disabled(! $editable) class="{{ $buttonClass }}">
                        Save and Continue
                    </button>
                </form>
            @endif

            @if($step === 3)
                <form wire:submit="savePreferences" class="space-y-6">
                    <p class="max-w-3xl text-sm leading-6 text-fg-muted">
                        Choose from approved master data only. These selections help match you with appropriate students after approval.
                    </p>

                    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                        <div class="relative" x-data="{ open: false, selected: $wire.entangle('subjectIds').live }" @click.outside="open = false" @keydown.escape.window="open = false">
                            <label class="mb-2 block text-sm font-medium text-fg">Subjects</label>
                            <button type="button" @click="open = ! open" @disabled(! $editable) class="{{ $dropdownButtonClass }}">
                                <span class="truncate" :class="selected.length ? 'text-fg-strong' : 'text-fg-faint'">
                                    <span x-text="selected.length ? `${selected.length} selected` : 'Select subjects'">{{ $selectedSummary($subjects, $subjectIds, 'Select subjects') }}</span>
                                </span>
                                <svg class="h-4 w-4 shrink-0 text-fg-muted transition" :class="{ 'rotate-180': open }" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            <div x-show="open" x-transition style="display: none;" class="absolute z-30 mt-2 max-h-72 w-full overflow-y-auto rounded-xl border border-edge bg-surface-solid p-2 shadow-2xl shadow-slate-900/10 dark:shadow-black/40">
                                @forelse($subjects as $subject)
                                    <label class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm text-fg transition hover:bg-surface-hover">
                                        <input type="checkbox" x-model="selected" value="{{ $subject['id'] }}" @disabled(! $editable) class="cursor-pointer rounded border-edge-strong bg-surface-raised text-indigo-500 focus:ring-indigo-400 disabled:cursor-not-allowed">
                                        <span>{{ $subject['name'] }}</span>
                                    </label>
                                @empty
                                    <p class="px-3 py-2 text-sm text-fg-faint">No subjects available.</p>
                                @endforelse
                            </div>
                            <p class="{{ $helpClass }}">Select every subject you are comfortable teaching.</p>
                            @error('subjectIds') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                        </div>

                        <div class="relative" x-data="{ open: false, selected: $wire.entangle('academicLevelIds').live }" @click.outside="open = false" @keydown.escape.window="open = false">
                            <label class="mb-2 block text-sm font-medium text-fg">Academic Levels</label>
                            <button type="button" @click="open = ! open" @disabled(! $editable) class="{{ $dropdownButtonClass }}">
                                <span class="truncate" :class="selected.length ? 'text-fg-strong' : 'text-fg-faint'">
                                    <span x-text="selected.length ? `${selected.length} selected` : 'Select academic levels'">{{ $selectedSummary($academicLevels, $academicLevelIds, 'Select academic levels') }}</span>
                                </span>
                                <svg class="h-4 w-4 shrink-0 text-fg-muted transition" :class="{ 'rotate-180': open }" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            <div x-show="open" x-transition style="display: none;" class="absolute z-30 mt-2 max-h-72 w-full overflow-y-auto rounded-xl border border-edge bg-surface-solid p-2 shadow-2xl shadow-slate-900/10 dark:shadow-black/40">
                                @forelse($academicLevels as $level)
                                    <label class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm text-fg transition hover:bg-surface-hover">
                                        <input type="checkbox" x-model="selected" value="{{ $level['id'] }}" @disabled(! $editable) class="cursor-pointer rounded border-edge-strong bg-surface-raised text-indigo-500 focus:ring-indigo-400 disabled:cursor-not-allowed">
                                        <span>{{ $level['name'] }}</span>
                                    </label>
                                @empty
                                    <p class="px-3 py-2 text-sm text-fg-faint">No academic levels available.</p>
                                @endforelse
                            </div>
                            <p class="{{ $helpClass }}">Choose the student levels you can support, such as middle school, high school, or test prep.</p>
                            @error('academicLevelIds') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                        </div>

                        <div class="relative" x-data="{ open: false, selected: $wire.entangle('teachingLanguageIds').live }" @click.outside="open = false" @keydown.escape.window="open = false">
                            <label class="mb-2 block text-sm font-medium text-fg">Teaching Languages</label>
                            <button type="button" @click="open = ! open" @disabled(! $editable) class="{{ $dropdownButtonClass }}">
                                <span class="truncate" :class="selected.length ? 'text-fg-strong' : 'text-fg-faint'">
                                    <span x-text="selected.length ? `${selected.length} selected` : 'Select teaching languages'">{{ $selectedSummary($languages, $teachingLanguageIds, 'Select teaching languages') }}</span>
                                </span>
                                <svg class="h-4 w-4 shrink-0 text-fg-muted transition" :class="{ 'rotate-180': open }" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            <div x-show="open" x-transition style="display: none;" class="absolute z-30 mt-2 max-h-72 w-full overflow-y-auto rounded-xl border border-edge bg-surface-solid p-2 shadow-2xl shadow-slate-900/10 dark:shadow-black/40">
                                @forelse($languages as $language)
                                    <label class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm text-fg transition hover:bg-surface-hover">
                                        <input type="checkbox" x-model="selected" value="{{ $language['id'] }}" @disabled(! $editable) class="cursor-pointer rounded border-edge-strong bg-surface-raised text-indigo-500 focus:ring-indigo-400 disabled:cursor-not-allowed">
                                        <span>{{ $language['name'] }}</span>
                                    </label>
                                @empty
                                    <p class="px-3 py-2 text-sm text-fg-faint">No languages available.</p>
                                @endforelse
                            </div>
                            <p class="{{ $helpClass }}">Select the languages you can confidently use while teaching.</p>
                            @error('teachingLanguageIds') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium text-fg">Country</label>
                            <select wire:model="countryId" @disabled(! $editable) class="{{ $selectClass }}">
                                <option value="">Select country</option>
                                @foreach($countries as $country)
                                    <option value="{{ $country['id'] }}">{{ $country['name'] }}</option>
                                @endforeach
                            </select>
                            <p class="{{ $helpClass }}">Optional, but useful for regional matching and document review.</p>
                            @error('countryId') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium text-fg">Timezone</label>
                            <select wire:model="timezone" @disabled(! $editable) class="{{ $selectClass }}">
                                <option value="">Select timezone</option>
                                @foreach($timezones as $zone)
                                    <option value="{{ $zone }}">{{ $zone }}</option>
                                @endforeach
                            </select>
                            <p class="{{ $helpClass }}">Optional. Use the timezone where you normally teach from.</p>
                            @error('timezone') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                        </div>

                        <div class="lg:col-span-2">
                            <label class="mb-2 block text-sm font-medium text-fg">Google account for Meet lessons</label>
                            <input type="email" wire:model="googleMeetAccount" @disabled(! $editable) placeholder="{{ auth()->user()->email }}" autocomplete="email" class="{{ $inputClass }}">
                            <p class="{{ $helpClass }}">Optional. The Google account you will join Google Meet lessons with — we make it the class co-host so you enter straight away and can admit students. Leave blank to use your login email.</p>
                            @error('googleMeetAccount') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <button type="submit" @disabled(! $editable) class="{{ $buttonClass }}">
                        Save and Continue
                    </button>
                </form>
            @endif

            @if($step === 4)
                <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <form wire:submit="saveEducation" class="space-y-5">
                        <p class="max-w-3xl text-sm leading-6 text-fg-muted">
                            Add your most relevant qualifications first. You can add multiple records one at a time.
                        </p>
                        @if(! $progress['status'])
                            @if($eligibility['eligible'] || $eligibility['code'] === 'missing_education_information')
                                <p class="rounded-lg border border-indigo-400/25 bg-indigo-500/10 px-4 py-3 text-sm text-indigo-700 dark:text-indigo-200" data-education-note>
                                    Saving your education will start your application.
                                </p>
                            @else
                                <p class="rounded-lg border border-rose-400/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-200" role="status">{{ $eligibility['reason'] }}</p>
                            @endif
                        @endif

                        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">Institution</label>
                                <input type="text" wire:model="educationForm.institution_name" @disabled(! $editable) placeholder="Example: University of Delhi" class="{{ $inputClass }}">
                                <p class="{{ $helpClass }}">Use the official school, college, university, or certification provider name.</p>
                                @error('educationForm.institution_name') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">Degree</label>
                                <input type="text" wire:model="educationForm.degree" @disabled(! $editable) placeholder="Example: B.Sc. Physics" class="{{ $inputClass }}">
                                <p class="{{ $helpClass }}">Enter the degree, diploma, certificate, or qualification title.</p>
                                @error('educationForm.degree') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">Field of study</label>
                                <input type="text" wire:model="educationForm.field_of_study" @disabled(! $editable) placeholder="Example: Physics, Mathematics, Computer Science" class="{{ $inputClass }}">
                                <p class="{{ $helpClass }}">Optional. Add the major, specialization, or subject area.</p>
                                @error('educationForm.field_of_study') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">Education level</label>
                                <select wire:model="educationForm.education_level" @disabled(! $editable) class="{{ $selectClass }}">
                                    @foreach($educationLevels as $level)
                                        <option value="{{ $level['value'] }}">{{ $level['label'] }}</option>
                                    @endforeach
                                </select>
                                <p class="{{ $helpClass }}">Choose the closest matching qualification level.</p>
                                @error('educationForm.education_level') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">Start date</label>
                                <input type="date" wire:model="educationForm.start_date" @disabled(! $editable) class="{{ $inputClass }}">
                                <p class="{{ $helpClass }}">Use the first month or approximate start date if exact date is unknown.</p>
                                @error('educationForm.start_date') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">End date</label>
                                <input type="date" wire:model="educationForm.end_date" @disabled(! $editable || $educationForm['is_current']) class="{{ $inputClass }}">
                                <p class="{{ $helpClass }}">Leave blank if you are currently studying.</p>
                                @error('educationForm.end_date') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div class="lg:col-span-2">
                                <label class="flex cursor-pointer items-center gap-2 text-sm text-fg-muted">
                                    <input type="checkbox" wire:model="educationForm.is_current" @disabled(! $editable) class="cursor-pointer rounded border-edge-strong bg-surface-raised disabled:cursor-not-allowed">
                                    Currently studying
                                </label>
                            </div>

                            <div class="lg:col-span-2">
                                <label class="mb-2 block text-sm font-medium text-fg">Notes</label>
                                <textarea wire:model="educationForm.description" @disabled(! $editable) rows="4" placeholder="Example: Relevant coursework, academic honors, thesis topic, or teaching-related achievements." class="{{ $inputClass }}"></textarea>
                                <p class="{{ $helpClass }}">Optional. Add details that help reviewers understand why this qualification matters.</p>
                                @error('educationForm.description') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <button type="submit" @disabled(! $editable) class="{{ $buttonClass }}">
                                Save and Continue
                            </button>
                            <button type="button" wire:click="saveEducation(false)" @disabled(! $editable) class="{{ $secondaryButtonClass }}">
                                Save and Add Another
                            </button>
                        </div>
                    </form>

                    <aside class="rounded-xl border border-edge bg-surface-solid/35 p-4">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-fg-muted">Saved education</h3>
                        <div class="mt-4 space-y-3">
                            @forelse($user->educations as $education)
                                <div class="rounded-lg border border-edge bg-surface-raised p-4">
                                    <p class="font-semibold text-fg-strong">{{ $education->degree }}</p>
                                    <p class="mt-1 text-sm text-fg-muted">{{ $education->institution_name }}</p>
                                    <div class="mt-3 flex gap-3">
                                        <button type="button" wire:click="editEducation({{ $education->id }})" @disabled(! $editable) class="cursor-pointer text-sm font-semibold text-indigo-600 dark:text-indigo-300 transition hover:text-indigo-700 hover:dark:text-indigo-200 disabled:cursor-not-allowed disabled:opacity-50">Edit</button>
                                        <button type="button" wire:click="deleteEducation({{ $education->id }})" @disabled(! $editable) class="cursor-pointer text-sm font-semibold text-rose-600 dark:text-rose-300 transition hover:text-rose-700 hover:dark:text-rose-200 disabled:cursor-not-allowed disabled:opacity-50">Delete</button>
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm leading-6 text-fg-muted">No education added yet.</p>
                            @endforelse
                        </div>
                    </aside>
                </div>
            @endif

            @if($step === 5)
                <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <form wire:submit="saveExperience" class="space-y-5">
                        <p class="max-w-3xl text-sm leading-6 text-fg-muted">
                            Add teaching, tutoring, mentoring, curriculum, lab, or relevant professional experience. You can add multiple records.
                        </p>

                        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">Organization</label>
                                <input type="text" wire:model="experienceForm.organization_name" @disabled(! $editable) placeholder="Example: Bright Future Academy" class="{{ $inputClass }}">
                                <p class="{{ $helpClass }}">Use the school, institute, company, platform, or private practice name.</p>
                                @error('experienceForm.organization_name') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">Designation</label>
                                <input type="text" wire:model="experienceForm.designation" @disabled(! $editable) placeholder="Example: Physics Tutor, STEM Instructor, Teaching Assistant" class="{{ $inputClass }}">
                                <p class="{{ $helpClass }}">Enter the title students or employers would recognize.</p>
                                @error('experienceForm.designation') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">Employment type</label>
                                <select wire:model="experienceForm.employment_type" @disabled(! $editable) class="{{ $selectClass }}">
                                    @foreach($employmentTypes as $type)
                                        <option value="{{ $type['value'] }}">{{ $type['label'] }}</option>
                                    @endforeach
                                </select>
                                <p class="{{ $helpClass }}">Select the closest match for this role.</p>
                                @error('experienceForm.employment_type') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">Industry</label>
                                <input type="text" wire:model="experienceForm.industry" @disabled(! $editable) placeholder="Example: Education, EdTech, Higher Education" class="{{ $inputClass }}">
                                <p class="{{ $helpClass }}">Optional. Helps reviewers understand the context of the role.</p>
                                @error('experienceForm.industry') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">Location</label>
                                <input type="text" wire:model="experienceForm.location" @disabled(! $editable) placeholder="Example: Remote, New Delhi, London" class="{{ $inputClass }}">
                                <p class="{{ $helpClass }}">Optional. You can enter a city, country, or Remote.</p>
                                @error('experienceForm.location') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">Skills</label>
                                <input type="text" wire:model="experienceForm.skills" @disabled(! $editable) placeholder="Example: Algebra, lesson planning, AP Physics, Python" class="{{ $inputClass }}">
                                <p class="{{ $helpClass }}">Optional. Separate skills with commas.</p>
                                @error('experienceForm.skills') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">Start date</label>
                                <input type="date" wire:model="experienceForm.start_date" @disabled(! $editable) class="{{ $inputClass }}">
                                <p class="{{ $helpClass }}">Use the first day of the month if you do not know the exact date.</p>
                                @error('experienceForm.start_date') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-fg">End date</label>
                                <input type="date" wire:model="experienceForm.end_date" @disabled(! $editable || $experienceForm['is_current']) class="{{ $inputClass }}">
                                <p class="{{ $helpClass }}">Leave blank if this is your current role.</p>
                                @error('experienceForm.end_date') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>

                            <div class="lg:col-span-2">
                                <label class="flex cursor-pointer items-center gap-2 text-sm text-fg-muted">
                                    <input type="checkbox" wire:model="experienceForm.is_current" @disabled(! $editable) class="cursor-pointer rounded border-edge-strong bg-surface-raised disabled:cursor-not-allowed">
                                    Current role
                                </label>
                            </div>

                            <div class="lg:col-span-2">
                                <label class="mb-2 block text-sm font-medium text-fg">Experience details</label>
                                <textarea wire:model="experienceForm.description" @disabled(! $editable) rows="4" placeholder="Describe what you taught, student levels, class size, curriculum, outcomes, or responsibilities." class="{{ $inputClass }}"></textarea>
                                <p class="{{ $helpClass }}">Optional, but useful for review. Focus on teaching responsibilities and outcomes.</p>
                                @error('experienceForm.description') <p class="{{ $errorClass }}">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <button type="submit" @disabled(! $editable) class="{{ $buttonClass }}">
                                Save and Continue
                            </button>
                            <button type="button" wire:click="saveExperience(false)" @disabled(! $editable) class="{{ $secondaryButtonClass }}">
                                Save and Add Another
                            </button>
                        </div>
                    </form>

                    <aside class="rounded-xl border border-edge bg-surface-solid/35 p-4">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-fg-muted">Saved experience</h3>
                        <div class="mt-4 space-y-3">
                            @forelse($user->experiences as $experience)
                                <div class="rounded-lg border border-edge bg-surface-raised p-4">
                                    <p class="font-semibold text-fg-strong">{{ $experience->designation }}</p>
                                    <p class="mt-1 text-sm text-fg-muted">{{ $experience->organization_name }}</p>
                                    <div class="mt-3 flex gap-3">
                                        <button type="button" wire:click="editExperience({{ $experience->id }})" @disabled(! $editable) class="cursor-pointer text-sm font-semibold text-indigo-600 dark:text-indigo-300 transition hover:text-indigo-700 hover:dark:text-indigo-200 disabled:cursor-not-allowed disabled:opacity-50">Edit</button>
                                        <button type="button" wire:click="deleteExperience({{ $experience->id }})" @disabled(! $editable) class="cursor-pointer text-sm font-semibold text-rose-600 dark:text-rose-300 transition hover:text-rose-700 hover:dark:text-rose-200 disabled:cursor-not-allowed disabled:opacity-50">Delete</button>
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm leading-6 text-fg-muted">No experience added yet.</p>
                            @endforelse
                        </div>
                    </aside>
                </div>
            @endif

            @if($step === 6)
                <div class="mb-5 rounded-xl border border-edge bg-surface-solid/35 px-4 py-3 text-sm leading-6 text-fg-muted">
                    Upload clear scans or photos. Documents stay private and are only used for application review.
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($documents as $document)
                        @php $property = $document['property']; @endphp

                        <div class="rounded-xl border border-edge bg-surface-solid/35 p-4">
                            <div class="mb-4 flex items-center justify-between gap-3">
                                <p class="font-semibold text-fg-strong">{{ $document['label'] }}</p>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $document['uploaded'] ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-200' : 'bg-amber-500/15 text-amber-700 dark:text-amber-200' }}">
                                    {{ $document['uploaded'] ? 'Uploaded' : 'Required' }}
                                </span>
                            </div>

                            <input type="file" wire:model="{{ $property }}" @disabled(! $editable) class="block w-full cursor-pointer text-sm text-fg-muted file:mr-4 file:cursor-pointer file:rounded-lg file:border-0 file:bg-indigo-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white file:transition hover:file:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60">
                            <p class="{{ $helpClass }}">{{ $document['help'] }}</p>
                            @error($property) <p class="{{ $errorClass }}">{{ $message }}</p> @enderror

                            <button type="button" wire:click="uploadDocument('{{ $document['collection'] }}')" @disabled(! $editable) class="mt-4 {{ $secondaryButtonClass }}">
                                {{ $document['uploaded'] ? 'Replace Document' : 'Upload Document' }}
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif

            @if($step === 7)
                <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_22rem]">
                    <div class="rounded-xl border border-edge bg-surface-solid/35 p-5">
                        @if($progress['missing'])
                            <h3 class="text-lg font-semibold text-fg-strong">Still needed</h3>
                            <p class="mt-1 text-sm text-fg-muted">Select an item to go to its step.</p>
                            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach(collect($progress['items'])->where('done', false) as $item)
                                    <button type="button" wire:click="$set('step', {{ $item['step'] }})" class="flex cursor-pointer items-center justify-between gap-3 rounded-lg border border-edge bg-surface-raised px-4 py-3 text-left text-sm text-fg-strong transition hover:border-indigo-400/40 hover:bg-surface-hover">
                                        <span>{{ $item['label'] }}</span>
                                        <span class="text-xs font-semibold text-indigo-600 dark:text-indigo-300">{{ $steps[$item['step']]['short'] }} →</span>
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <h3 class="text-lg font-semibold text-fg-strong">Ready to submit</h3>
                            <p class="mt-2 text-sm leading-6 text-emerald-700 dark:text-emerald-200">
                                Your application has all required sections completed.
                            </p>
                        @endif
                    </div>

                    <div class="rounded-xl border border-edge bg-surface-solid/35 p-5">
                        <h3 class="text-lg font-semibold text-fg-strong">Submit application</h3>
                        <p class="mt-2 text-sm leading-6 text-fg-muted">
                            After submission, your application will be locked while the admin team reviews it.
                        </p>
                        <button type="button"
                                wire:click="submit"
                                @disabled($progress['next_action'] !== 'submit_application')
                                class="mt-5 inline-flex w-full cursor-pointer items-center justify-center rounded-lg bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50">
                            Submit Application
                        </button>
                    </div>
                </div>
            @endif
        </section>
    </div>
</div>
