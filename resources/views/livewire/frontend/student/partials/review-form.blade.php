{{-- Shared submit/edit review form. Expects: $submitAction, $mode,
     $formRatingMin, $formRatingMax, $formDimensionsEnabled, $tagOptions --}}
<form wire:submit="{{ $submitAction }}" class="space-y-4">
    <div>
        <label class="block text-xs font-semibold uppercase tracking-wide text-fg-muted mb-1">Overall rating *</label>
        <select wire:model="overall_rating"
            class="w-full max-w-[160px] rounded-xl border border-edge bg-surface-raised px-3 py-2 text-sm text-fg-strong focus:border-indigo-400 focus:outline-none">
            @for ($rating = $formRatingMax; $rating >= $formRatingMin; $rating--)
                <option value="{{ $rating }}">{{ $rating }} ★</option>
            @endfor
        </select>
        @error('overall_rating') <p class="mt-1 text-xs text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
    </div>

    @if ($formDimensionsEnabled)
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                'teaching_quality_rating' => 'Teaching quality',
                'communication_rating' => 'Communication',
                'punctuality_rating' => 'Punctuality',
                'preparedness_rating' => 'Preparedness',
                'learning_value_rating' => 'Learning value',
            ] as $field => $label)
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-fg-muted mb-1">{{ $label }}</label>
                    <select wire:model="{{ $field }}"
                        class="w-full rounded-xl border border-edge bg-surface-raised px-3 py-2 text-sm text-fg-strong focus:border-indigo-400 focus:outline-none">
                        <option value="">Not rated</option>
                        @for ($rating = $formRatingMax; $rating >= $formRatingMin; $rating--)
                            <option value="{{ $rating }}">{{ $rating }} ★</option>
                        @endfor
                    </select>
                    @error($field) <p class="mt-1 text-xs text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                </div>
            @endforeach
        </div>
    @endif

    <div>
        <label class="block text-xs font-semibold uppercase tracking-wide text-fg-muted mb-1">
            {{ $mode === 'private_feedback' ? 'Private feedback' : 'Your review' }}
        </label>
        <textarea wire:model="content" rows="4" maxlength="10000"
            class="w-full rounded-xl border border-edge bg-surface-raised px-3 py-2 text-sm text-fg-strong focus:border-indigo-400 focus:outline-none"></textarea>
        @error('content') <p class="mt-1 text-xs text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
    </div>

    @if ($tagOptions !== [])
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-fg-muted mb-2">Tags</label>
            <div class="flex flex-wrap gap-2">
                @foreach ($tagOptions as $tag)
                    <label wire:key="tag-{{ $tag['key'] }}" class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-surface-raised px-3 py-1.5 text-xs text-fg ring-1 ring-edge has-[:checked]:bg-indigo-500/20 has-[:checked]:ring-indigo-400/40">
                        <input type="checkbox" wire:model="selectedTags" value="{{ $tag['key'] }}" class="h-3 w-3 rounded border-edge-strong bg-transparent">
                        {{ $tag['label'] }}
                    </label>
                @endforeach
            </div>
            @error('selectedTags') <p class="mt-1 text-xs text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
        </div>
    @endif

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" wire:loading.attr="disabled" wire:target="{{ $submitAction }}"
            class="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-500 hover:bg-indigo-400 transition disabled:opacity-50">
            {{ $submitAction === 'saveEdit' ? 'Save changes' : 'Submit' }}
        </button>
        <button type="button" wire:click="cancelForm"
            class="px-4 py-2 rounded-xl text-sm font-semibold text-fg-muted border border-edge hover:bg-surface-hover transition">
            Cancel
        </button>
    </div>
</form>
