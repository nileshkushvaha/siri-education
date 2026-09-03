<div>
    @if($canReport)
        <button
            type="button"
            wire:click="openModal"
            class="text-xs font-medium text-fg-faint underline decoration-dotted transition hover:text-fg-muted"
        >
            Report Review
        </button>

        @if($successMessage)
            <p class="mt-1 text-xs text-emerald-600 dark:text-emerald-300">{{ $successMessage }}</p>
        @endif

        <x-ui.modal :id="$modalId" title="Report this review" size="md">
            <div x-data x-init="$nextTick(() => $el.querySelector('[data-autofocus]')?.focus())">
                <p class="text-sm text-fg-muted">
                    Let us know why this review should be reviewed by our moderation team.
                    Reports are confidential — the review's author is never told who reported it.
                </p>

                @error('form')
                    <div class="mt-3 rounded-xl border border-rose-400/30 bg-rose-500/10 p-3 text-sm text-rose-700 dark:text-rose-200" role="alert">
                        {{ $message }}
                    </div>
                @enderror

                <div class="mt-4">
                    <label for="report-reason-{{ $reviewId }}" class="text-xs font-bold uppercase tracking-wide text-fg-muted">
                        Reason
                    </label>
                    <select
                        id="report-reason-{{ $reviewId }}"
                        wire:model="selectedReason"
                        data-autofocus
                        class="mt-1.5 w-full rounded-xl border border-edge bg-surface-raised px-3 py-2 text-sm text-fg-strong focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/20"
                        aria-describedby="report-reason-error-{{ $reviewId }}"
                    >
                        <option value="">Select a reason…</option>
                        @foreach($reasons as $reason)
                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                        @endforeach
                    </select>
                    @error('selectedReason')
                        <p id="report-reason-error-{{ $reviewId }}" class="mt-1 text-xs text-rose-600 dark:text-rose-300">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mt-4">
                    <label for="report-explanation-{{ $reviewId }}" class="text-xs font-bold uppercase tracking-wide text-fg-muted">
                        Additional details (optional)
                    </label>
                    <textarea
                        id="report-explanation-{{ $reviewId }}"
                        wire:model="explanation"
                        rows="3"
                        maxlength="1000"
                        class="mt-1.5 w-full rounded-xl border border-edge bg-surface-raised px-3 py-2 text-sm text-fg-strong focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-400/20"
                        aria-describedby="report-explanation-error-{{ $reviewId }} report-explanation-hint-{{ $reviewId }}"
                    ></textarea>
                    <p id="report-explanation-hint-{{ $reviewId }}" class="mt-1 text-xs text-fg-faint">
                        Do not include phone numbers, email addresses or other private information.
                    </p>
                    @error('explanation')
                        <p id="report-explanation-error-{{ $reviewId }}" class="mt-1 text-xs text-rose-600 dark:text-rose-300">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        wire:click="cancel"
                        wire:loading.attr="disabled"
                        wire:target="submitReport"
                        class="rounded-xl px-4 py-2 text-sm font-bold text-fg-muted transition hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="submitReport"
                        wire:loading.attr="disabled"
                        wire:target="submitReport"
                        class="rounded-xl bg-rose-500 px-4 py-2 text-sm font-bold text-white transition hover:bg-rose-400 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="submitReport">Submit Report</span>
                        <span wire:loading wire:target="submitReport">Submitting…</span>
                    </button>
                </div>
            </div>
        </x-ui.modal>
    @endif
</div>
