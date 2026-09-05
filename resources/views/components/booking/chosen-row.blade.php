{{--
    A question that has already been answered inside the current stage:
    shows the choice compactly with a "Change" action that re-opens it.

    Props: label, value, phase (internal phase key passed to editPhase)
--}}
@props(['label', 'value', 'phase'])

<div {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3 rounded-2xl border border-edge bg-surface px-4 py-3']) }}>
    <div class="min-w-0">
        <p class="text-[11px] font-bold uppercase tracking-wide text-fg-muted">{{ $label }}</p>
        <p class="truncate text-sm font-bold text-fg-strong">{{ $value }}</p>
    </div>
    <button
        type="button"
        wire:click="editPhase('{{ $phase }}')"
        class="inline-flex min-h-9 shrink-0 items-center rounded-lg px-2.5 text-sm font-bold text-indigo-600 transition hover:bg-indigo-500/10 focus:outline-none focus-visible:ring-4 focus-visible:ring-indigo-300/50 dark:text-indigo-300"
    >
        Change<span class="sr-only"> {{ $label }}</span>
    </button>
</div>
