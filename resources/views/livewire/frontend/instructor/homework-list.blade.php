<div>
    @if (session('success'))
        <div class="mb-4 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-600 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    <div class="mb-6">
        @if (! $showAssignForm)
            <button wire:click="openAssignForm"
                    class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all">
                Assign Homework
            </button>
        @else
            <x-account.card>
                <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-600 dark:text-indigo-300 mb-2">Assign Homework</h2>
                <p class="text-xs text-fg-muted mb-4">Homework must relate to a lesson or a learning plan — select at least one below.</p>

                <div class="grid gap-3">
                    <div>
                        <label class="block text-xs text-fg-muted mb-1">Student</label>
                        <select wire:model.live="assignStudentId"
                                class="w-full rounded-xl bg-surface-raised border border-edge text-sm text-fg px-3 py-2 focus:outline-none focus:border-indigo-500/40">
                            <option value="">Choose a student…</option>
                            @foreach ($studentOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('assignStudentId')<p class="text-xs text-rose-600 dark:text-rose-400 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs text-fg-muted mb-1">Lesson (completed)</label>
                            <select wire:model="assignBookingId"
                                    class="w-full rounded-xl bg-surface-raised border border-edge text-sm text-fg px-3 py-2 focus:outline-none focus:border-indigo-500/40">
                                <option value="">No lesson link</option>
                                @foreach ($bookingOptions as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-fg-muted mb-1">Learning plan</label>
                            <select wire:model="assignPlanId"
                                    class="w-full rounded-xl bg-surface-raised border border-edge text-sm text-fg px-3 py-2 focus:outline-none focus:border-indigo-500/40">
                                <option value="">No plan link</option>
                                @foreach ($planOptions as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @error('assignContext')<p class="text-xs text-rose-600 dark:text-rose-400" role="alert">{{ $message }}</p>@enderror

                    <div>
                        <label class="block text-xs text-fg-muted mb-1">Title</label>
                        <input type="text" wire:model="assignTitle"
                               class="w-full rounded-xl bg-surface-raised border border-edge text-sm text-fg px-3 py-2 focus:outline-none focus:border-indigo-500/40" />
                        @error('assignTitle')<p class="text-xs text-rose-600 dark:text-rose-400 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs text-fg-muted mb-1">Subject</label>
                            <input type="text" wire:model="assignSubject"
                                   class="w-full rounded-xl bg-surface-raised border border-edge text-sm text-fg px-3 py-2 focus:outline-none focus:border-indigo-500/40" />
                            @error('assignSubject')<p class="text-xs text-rose-600 dark:text-rose-400 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs text-fg-muted mb-1">Due date</label>
                            <input type="datetime-local" wire:model="assignDueAt"
                                   class="w-full rounded-xl bg-surface-raised border border-edge text-sm text-fg px-3 py-2 focus:outline-none focus:border-indigo-500/40" />
                            @error('assignDueAt')<p class="text-xs text-rose-600 dark:text-rose-400 mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-fg-muted mb-1">Description (optional)</label>
                        <textarea wire:model="assignDescription" rows="3"
                                  class="w-full rounded-xl bg-surface-raised border border-edge text-sm text-fg px-3 py-2 focus:outline-none focus:border-indigo-500/40"></textarea>
                        @error('assignDescription')<p class="text-xs text-rose-600 dark:text-rose-400 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="assignResource" class="block text-xs text-fg-muted mb-1">Resource for the student (optional, PDF or image)</label>
                        <input type="file" id="assignResource" wire:model="assignResource" accept=".pdf,.jpg,.jpeg,.png,.webp" class="text-xs text-fg-muted">
                        @error('assignResource')<p class="text-xs text-rose-600 dark:text-rose-400 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex items-center gap-2">
                        <button wire:click="assign" wire:loading.attr="disabled"
                                class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all disabled:opacity-50">
                            Assign
                        </button>
                        <button wire:click="cancelAssign"
                                class="px-4 py-2 rounded-xl text-xs font-medium text-fg-muted hover:text-fg-strong transition-all">
                            Cancel
                        </button>
                    </div>
                </div>
            </x-account.card>
        @endif
    </div>

    <div class="mb-4 flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-amber-600 dark:text-amber-300">Pending Review</h2>
        <span class="rounded-full bg-amber-400/10 px-2.5 py-1 text-xs font-bold text-amber-700 dark:text-amber-200">{{ $pending->total() }}</span>
    </div>

    <div class="mb-8">
    <x-account.card>
        @forelse($pending as $assignment)
            <div wire:key="pending-{{ $assignment->id }}" class="py-4 {{ !$loop->last ? 'border-b border-edge' : '' }}">
                <div class="flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="text-sm font-medium text-fg-strong truncate">{{ $assignment->title }}</p>
                            <x-ui.badge :color="$assignment->status->color()">{{ $assignment->status->label() }}</x-ui.badge>
                        </div>
                        <p class="text-xs text-fg-muted">{{ $assignment->subject }} &middot; {{ $assignment->student?->name ?? 'Student' }}</p>
                        <x-homework.context-line :assignment="$assignment" />
                        <p class="text-xs text-fg-muted mt-1">Submitted {{ viewer_datetime($assignment->submitted_at) }}</p>
                    </div>

                    @if($reviewingId !== $assignment->id)
                        <button wire:click="startReview('{{ $assignment->id }}')"
                                class="flex-shrink-0 px-4 py-2 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all">
                            Review
                        </button>
                    @endif
                </div>

                @if($assignment->submission_text)
                    <p class="mt-3 rounded-xl bg-surface-raised px-3 py-2 text-xs leading-5 text-fg-muted">{{ $assignment->submission_text }}</p>
                @endif

                @if($assignment->getMedia('submission_attachments')->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach($assignment->getMedia('submission_attachments') as $media)
                            <a href="{{ route('dashboard.homework.resources.download', $media) }}" class="inline-flex items-center gap-1.5 text-xs text-indigo-600 dark:text-indigo-300 underline">
                                @if(str_starts_with($media->mime_type, 'image/'))
                                    <img src="{{ route('dashboard.homework.resources.download', $media) }}?preview=1" alt="" class="h-6 w-6 rounded object-cover no-underline">
                                @endif
                                {{ $media->file_name }} ({{ $media->human_readable_size }})
                            </a>
                        @endforeach
                    </div>
                @endif

                <div class="mt-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-fg-faint mb-1.5">Resources</p>
                    @foreach($assignment->getMedia('instructor_resources') as $media)
                        <div class="flex items-center gap-2 text-xs text-fg-muted mb-1" wire:key="resource-{{ $media->id }}">
                            @if(str_starts_with($media->mime_type, 'image/'))
                                <img src="{{ route('dashboard.homework.resources.download', $media) }}?preview=1" alt="" class="h-6 w-6 rounded object-cover">
                            @endif
                            <a href="{{ route('dashboard.homework.resources.download', $media) }}" class="underline text-indigo-600 dark:text-indigo-300">{{ $media->file_name }}</a>
                            <span class="text-fg-faint">({{ $media->human_readable_size }})</span>
                            <button wire:click="removeResource('{{ $assignment->id }}', '{{ $media->id }}')"
                                    wire:confirm="Remove this resource?"
                                    class="text-rose-600 dark:text-rose-400 hover:text-rose-600 hover:dark:text-rose-300">Remove</button>
                        </div>
                    @endforeach

                    @if($resourceAssignmentId === $assignment->id)
                        <div class="mt-2">
                            <input type="file" wire:model="newResource" accept=".pdf,.jpg,.jpeg,.png,.webp" class="text-xs text-fg-muted">
                            @error('newResource')<p class="text-xs text-rose-600 dark:text-rose-400 mt-1">{{ $message }}</p>@enderror
                            <div class="flex items-center gap-2 mt-2">
                                <button wire:click="uploadResource('{{ $assignment->id }}')"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all">
                                    Upload
                                </button>
                                <button wire:click="cancelAddResource" class="px-3 py-1.5 rounded-lg text-xs font-medium text-fg-muted hover:text-fg-strong transition-all">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    @else
                        <button wire:click="startAddResource('{{ $assignment->id }}')" class="mt-1 text-xs text-indigo-600 dark:text-indigo-300 underline">
                            + Add resource
                        </button>
                    @endif
                </div>

                @if($assignment->resourceVersions->isNotEmpty())
                    <div class="mt-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-fg-faint mb-1.5">Library Resources</p>
                        @foreach($assignment->resourceVersions as $version)
                            <div class="flex items-center gap-2 text-xs text-fg-muted mb-1" wire:key="lib-{{ $version->id }}">
                                @if($version->getFirstMedia('file'))
                                    @if(str_starts_with($version->getFirstMedia('file')->mime_type, 'image/'))
                                        <img src="{{ route('dashboard.homework.resources.download', $version->getFirstMedia('file')) }}?preview=1" alt="" class="h-6 w-6 rounded object-cover">
                                    @endif
                                    <a href="{{ route('dashboard.homework.resources.download', $version->getFirstMedia('file')) }}" class="underline text-indigo-600 dark:text-indigo-300">
                                        {{ $version->resource->title }} (v{{ $version->version_number }})
                                    </a>
                                @endif
                                <button wire:click="detachLibraryVersion('{{ $assignment->id }}', '{{ $version->id }}')" wire:confirm="Detach this resource?" class="text-rose-600 dark:text-rose-400 hover:text-rose-600 hover:dark:text-rose-300">Detach</button>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($reviewingId === $assignment->id)
                    @php($aiDraft = $this->activeDraftFor($assignment))

                    {{-- AI feedback copilot. The assistant only ever runs
                         because the instructor asked, on this one
                         submission, and its output is a draft the
                         instructor edits before publishing. --}}
                    <div class="mt-4 rounded-xl border border-edge bg-surface-raised p-3">
                        @if($aiDraft === null)
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-fg-faint">AI feedback assistant</p>
                                    <p class="text-xs text-fg-muted mt-1">
                                        Drafts feedback from this submission to save you time. It never grades, and nothing is sent to the student until you publish it yourself.
                                    </p>
                                </div>
                                <button wire:click="generateAiDraft('{{ $assignment->id }}')"
                                        wire:confirm="This sends the student's written submission and the assignment details to the AI provider to draft feedback. Names and contact details are removed first. Attachments are never sent. Continue?"
                                        wire:loading.attr="disabled"
                                        class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-semibold text-indigo-700 dark:text-indigo-200 bg-indigo-500/10 border border-indigo-400/20 hover:bg-indigo-500/20 transition-all">
                                    Generate draft
                                </button>
                            </div>
                            @error('aiDraft')
                                <p class="text-xs text-rose-600 dark:text-rose-400 mt-2">{{ $message }}</p>
                            @enderror
                        @elseif($aiDraft->status === \App\Homework\Copilot\Enums\HomeworkFeedbackDraftStatus::Pending)
                            <div wire:poll.5s class="flex items-center gap-2 text-xs text-fg-muted">
                                <span class="h-1.5 w-1.5 rounded-full bg-indigo-400 animate-pulse"></span>
                                Drafting feedback…
                            </div>
                        @elseif($aiDraft->status === \App\Homework\Copilot\Enums\HomeworkFeedbackDraftStatus::Failed)
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-xs text-fg-muted">
                                    No draft was produced. You can write your feedback as usual, or try again.
                                </p>
                                <button wire:click="discardAiDraft('{{ $aiDraft->id }}')"
                                        class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-medium text-fg-muted hover:text-fg-strong transition-all">
                                    Dismiss
                                </button>
                            </div>
                        @else
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-fg-faint">AI draft — review before using</p>
                                @if($aiDraft->confidencePercent() !== null)
                                    <span class="shrink-0 text-[11px] text-fg-faint" title="The model's own stated certainty. Not a mark, and not a measure of correctness.">
                                        AI confidence {{ $aiDraft->confidencePercent() }}%
                                    </span>
                                @endif
                            </div>

                            @if($aiDraft->summary)
                                <p class="text-xs text-fg-muted mt-2">{{ $aiDraft->summary }}</p>
                            @endif

                            @if(filled($aiDraft->strengths))
                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-fg-faint mt-3">Strengths noted</p>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach($aiDraft->strengths as $strength)
                                        <li class="text-xs text-fg-muted">• {{ $strength }}</li>
                                    @endforeach
                                </ul>
                            @endif

                            @if(filled($aiDraft->improvements))
                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-fg-faint mt-3">Possible improvements</p>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach($aiDraft->improvements as $improvement)
                                        <li class="text-xs text-fg-muted">• {{ $improvement }}</li>
                                    @endforeach
                                </ul>
                            @endif

                            @if($aiDraft->suggested_feedback)
                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-fg-faint mt-3">Suggested wording</p>
                                <p class="text-xs text-fg-muted mt-1 whitespace-pre-line">{{ $aiDraft->suggested_feedback }}</p>
                            @endif

                            <p class="text-[11px] text-amber-300/80 mt-3">
                                Suggestions only — check them against the work before using. You decide the feedback and the grade.
                            </p>

                            <div class="flex items-center gap-2 mt-3">
                                <button wire:click="useAiDraft('{{ $aiDraft->id }}')"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-indigo-700 dark:text-indigo-200 bg-indigo-500/10 border border-indigo-400/20 hover:bg-indigo-500/20 transition-all">
                                    Use as a starting point
                                </button>
                                <button wire:click="discardAiDraft('{{ $aiDraft->id }}')"
                                        class="px-3 py-1.5 rounded-lg text-xs font-medium text-fg-muted hover:text-fg-strong transition-all">
                                    Discard
                                </button>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4">
                        <textarea wire:model="feedbackText" rows="3"
                                  class="w-full rounded-xl bg-surface-raised border border-edge text-sm text-fg px-3 py-2 focus:outline-none focus:border-indigo-500/40"
                                  placeholder="Feedback for the student..."></textarea>
                        @error('feedbackText')
                            <p class="text-xs text-rose-600 dark:text-rose-400 mt-1">{{ $message }}</p>
                        @enderror

                        <input type="text" wire:model="grade"
                               class="mt-3 w-full max-w-[10rem] rounded-xl bg-surface-raised border border-edge text-sm text-fg px-3 py-2 focus:outline-none focus:border-indigo-500/40"
                               placeholder="Grade (optional)" />
                        @error('grade')
                            <p class="text-xs text-rose-600 dark:text-rose-400 mt-1">{{ $message }}</p>
                        @enderror

                        <div class="flex items-center gap-2 mt-3">
                            <button wire:click="submitReview"
                                    class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all">
                                Mark Reviewed
                            </button>
                            <button wire:click="cancelReview"
                                    class="px-4 py-2 rounded-xl text-xs font-medium text-fg-muted hover:text-fg-strong transition-all">
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
            <div class="mt-6 pt-4 border-t border-edge">
                {{ $pending->links() }}
            </div>
        @endif
    </x-account.card>
    </div>

    <div class="mb-4">
        <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-emerald-600 dark:text-emerald-300">Recently Reviewed</h2>
    </div>

    <x-account.card>
        @forelse($recentlyGraded as $assignment)
            <div wire:key="graded-{{ $assignment->id }}" class="py-4 {{ !$loop->last ? 'border-b border-edge' : '' }}">
                <div class="flex items-center gap-2 mb-1">
                    <p class="text-sm font-medium text-fg-strong truncate">{{ $assignment->title }}</p>
                    <x-ui.badge :color="$assignment->status->color()">{{ $assignment->status->label() }}</x-ui.badge>
                </div>
                <p class="text-xs text-fg-muted">{{ $assignment->subject }} &middot; {{ $assignment->student?->name ?? 'Student' }}</p>
                <x-homework.context-line :assignment="$assignment" />
                @if($assignment->grade)
                    <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1">Grade: {{ $assignment->grade }}</p>
                @endif
                @if($assignment->feedback)
                    <p class="mt-2 rounded-xl bg-surface-raised px-3 py-2 text-xs leading-5 text-fg-muted">{{ $assignment->feedback }}</p>
                @endif
            </div>
        @empty
            <x-ui.empty-state title="No reviewed homework yet" description="Homework you've reviewed and graded will appear here." />
        @endforelse
    </x-account.card>
</div>
