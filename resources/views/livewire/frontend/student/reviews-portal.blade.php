<div class="space-y-6">
    @if (session('reviews-status'))
        <div class="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 p-4 text-sm text-emerald-200">
            {{ session('reviews-status') }}
        </div>
    @endif

    @error('form')
        <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 p-4 text-sm text-rose-200">
            {{ $message }}
        </div>
    @enderror

    {{-- Open review opportunities --}}
    <x-account.card title="Review Opportunities">
        @forelse ($openEligibilities as $eligibility)
            <div wire:key="eligibility-{{ $eligibility->id }}" class="py-4 {{ ! $loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <p class="text-sm font-medium text-white truncate">{{ $eligibility->instructor?->name ?? 'Instructor' }}</p>
                            <x-ui.badge color="info">{{ ucfirst($eligibility->lesson_type->value) }} lesson</x-ui.badge>
                            <x-ui.badge color="gray">{{ $eligibility->eligibility_mode->label() }}</x-ui.badge>
                        </div>
                        <p class="text-xs text-slate-400">
                            Lesson on {{ viewer_date($eligibility->lesson?->starts_at) ?? '—' }}
                            &middot; Review by {{ viewer_date($eligibility->expires_at) }}
                        </p>
                    </div>
                    @if ($submittingEligibilityId !== $eligibility->id)
                        <button type="button" wire:click="startSubmit('{{ $eligibility->id }}')"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition">
                            {{ $eligibility->eligibility_mode->value === 'private_feedback' ? 'Give private feedback' : 'Write a review' }}
                        </button>
                    @endif
                </div>

                @if ($submittingEligibilityId === $eligibility->id)
                    <div class="mt-4 rounded-xl border border-white/[0.07] bg-white/[0.02] p-4">
                        @include('livewire.frontend.student.partials.review-form', [
                            'submitAction' => 'submitReview',
                            'mode' => $eligibility->eligibility_mode->value,
                            'formRatingMin' => $ratingMin,
                            'formRatingMax' => $ratingMax,
                            'formDimensionsEnabled' => $dimensionsEnabled,
                            'tagOptions' => $tagOptions[$eligibility->eligibility_mode->value] ?? [],
                        ])
                    </div>
                @endif
            </div>
        @empty
            <p class="py-4 text-sm text-slate-400">No open review opportunities right now. They appear here after an eligible completed lesson.</p>
        @endforelse
    </x-account.card>

    {{-- Submitted reviews --}}
    <x-account.card title="My Reviews & Feedback">
        @forelse ($reviews as $review)
            @php($canEdit = $editability[$review->id] ?? null)
            <div wire:key="review-{{ $review->id }}" class="py-4 {{ ! $loop->last ? 'border-b border-white/[0.05]' : '' }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <p class="text-sm font-medium text-white truncate">{{ $review->instructor?->name ?? 'Instructor' }}</p>
                            <x-ui.badge :color="$review->status->color()">{{ $review->status->label() }}</x-ui.badge>
                            <x-ui.badge color="gray">{{ $review->review_mode->label() }}</x-ui.badge>
                            <span class="rounded-full bg-amber-400/10 px-2.5 py-1 text-xs font-semibold text-amber-300 ring-1 ring-amber-400/20">{{ $review->overall_rating }} ★</span>
                        </div>
                        <p class="text-xs text-slate-400">
                            Lesson on {{ viewer_date($review->lesson?->starts_at) ?? '—' }}
                            &middot; Submitted {{ viewer_date($review->submitted_at) }}
                            @if ($review->revisions_count > 0)
                                &middot; Edited {{ $review->revisions_count }} {{ \Illuminate\Support\Str::plural('time', $review->revisions_count) }}
                            @endif
                        </p>
                        @if ($review->content)
                            <p class="mt-2 text-sm leading-6 text-slate-300">{{ $review->content }}</p>
                        @endif
                    </div>
                    <div class="shrink-0">
                        @if ($canEdit?->editable && $editingReviewId !== $review->id)
                            <button type="button" wire:click="startEdit('{{ $review->id }}')"
                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-300 border border-white/[0.10] hover:bg-white/[0.05] transition">
                                Edit
                            </button>
                            <p class="mt-1 text-[11px] text-slate-500">Editable until {{ viewer_datetime_labelled($canEdit->deadline, 'M j, g:i A') }}</p>
                        @elseif ($canEdit && ! $canEdit->editable)
                            <p class="max-w-[200px] text-right text-[11px] text-slate-500">{{ $canEdit->reason }}</p>
                        @endif
                    </div>
                </div>

                @if ($editingReviewId === $review->id)
                    <div class="mt-4 rounded-xl border border-white/[0.07] bg-white/[0.02] p-4">
                        <p class="mb-3 text-xs text-slate-500">Edited reviews are re-checked before they appear publicly again.</p>
                        @include('livewire.frontend.student.partials.review-form', [
                            'submitAction' => 'saveEdit',
                            'mode' => $review->review_mode->value,
                            'formRatingMin' => (int) ($review->settings_snapshot['rating_min'] ?? $ratingMin),
                            'formRatingMax' => (int) ($review->settings_snapshot['rating_max'] ?? $ratingMax),
                            'formDimensionsEnabled' => (bool) ($review->settings_snapshot['rating_dimensions_enabled'] ?? $dimensionsEnabled),
                            'tagOptions' => $tagOptions[$review->review_mode->value] ?? [],
                        ])
                    </div>
                @endif
            </div>
        @empty
            <p class="py-4 text-sm text-slate-400">You have not submitted any reviews or private feedback yet.</p>
        @endforelse
    </x-account.card>

    {{-- Past opportunities without an action --}}
    @if ($closedEligibilities->isNotEmpty())
        <x-account.card title="Past Opportunities">
            @foreach ($closedEligibilities as $eligibility)
                <div wire:key="closed-{{ $eligibility->id }}" class="flex items-center justify-between py-3 text-sm {{ ! $loop->last ? 'border-b border-white/[0.05]' : '' }}">
                    <div>
                        <span class="text-slate-300">{{ $eligibility->instructor?->name ?? 'Instructor' }}</span>
                        <span class="ml-2 text-xs text-slate-500">Lesson on {{ viewer_date($eligibility->lesson?->starts_at) ?? '—' }}</span>
                    </div>
                    <x-ui.badge color="gray">{{ ucfirst(str_replace('_', ' ', $eligibility->status->value)) }}</x-ui.badge>
                </div>
            @endforeach
        </x-account.card>
    @endif

    {{-- Status explanations --}}
    <x-account.card title="What review statuses mean">
        <dl class="space-y-2 text-sm">
            <div class="flex gap-3"><dt class="w-24 shrink-0 font-semibold text-slate-300">Submitted</dt><dd class="text-slate-400">Your review is being checked before publication.</dd></div>
            <div class="flex gap-3"><dt class="w-24 shrink-0 font-semibold text-slate-300">Published</dt><dd class="text-slate-400">Your review is live on the instructor's profile.</dd></div>
            <div class="flex gap-3"><dt class="w-24 shrink-0 font-semibold text-slate-300">Private</dt><dd class="text-slate-400">Your feedback was shared privately and never appears publicly.</dd></div>
            <div class="flex gap-3"><dt class="w-24 shrink-0 font-semibold text-slate-300">Flagged</dt><dd class="text-slate-400">Your review is being reviewed by our team before a decision.</dd></div>
            <div class="flex gap-3"><dt class="w-24 shrink-0 font-semibold text-slate-300">Hidden</dt><dd class="text-slate-400">Your review is not currently visible publicly.</dd></div>
            <div class="flex gap-3"><dt class="w-24 shrink-0 font-semibold text-slate-300">Rejected</dt><dd class="text-slate-400">Your review did not meet the review guidelines.</dd></div>
        </dl>
    </x-account.card>
</div>
