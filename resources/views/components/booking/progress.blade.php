{{--
    Conceptual booking progress: the four student-facing stages, not the
    internal phase list. Completed stages show their summary and an Edit
    action; the current stage carries aria-current="step".

    Props:
        stages:  list<array{key, number, label, state, summary}>
        current: key of the current stage
--}}
@props(['stages', 'current'])

@php
    $currentStage = collect($stages)->firstWhere('key', $current);
    $total = count($stages);
@endphp

<nav aria-label="Booking progress" {{ $attributes }}>
    {{-- Mobile: one line of context plus a segmented bar --}}
    <div class="sm:hidden">
        <p class="text-sm font-bold text-fg-strong">
            <span class="text-fg-muted">{{ $currentStage['number'] ?? 1 }} of {{ $total }}</span>
            <span aria-hidden="true"> • </span>
            {{ $currentStage['label'] ?? '' }}
        </p>
        <ol class="mt-2 grid gap-1.5" style="grid-template-columns: repeat({{ $total }}, minmax(0, 1fr))" role="list">
            @foreach($stages as $stage)
                <li
                    @if($stage['state'] === 'current') aria-current="step" @endif
                    class="h-1.5 rounded-full {{ $stage['state'] === 'upcoming' ? 'bg-edge' : 'bg-indigo-500' }}"
                >
                    <span class="sr-only">{{ $stage['label'] }}{{ $stage['state'] === 'complete' ? ' (completed)' : '' }}</span>
                </li>
            @endforeach
        </ol>
    </div>

    {{-- Tablet and up: numbered stages with connectors --}}
    <ol class="hidden items-start sm:flex" role="list">
        @foreach($stages as $stage)
            @php($isLast = $loop->last)
            <li
                @if($stage['state'] === 'current') aria-current="step" @endif
                class="flex min-w-0 {{ $isLast ? 'flex-none' : 'flex-1' }} items-start"
            >
                <div class="flex min-w-0 items-start gap-2.5">
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-black
                            {{ $stage['state'] === 'complete' ? 'bg-emerald-500 text-white' : '' }}
                            {{ $stage['state'] === 'current' ? 'bg-indigo-600 text-white ring-4 ring-indigo-500/20' : '' }}
                            {{ $stage['state'] === 'upcoming' ? 'border-2 border-edge-strong text-fg-muted' : '' }}"
                    >
                        @if($stage['state'] === 'complete')
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            <span class="sr-only">Completed:</span>
                        @else
                            {{ $stage['number'] }}
                        @endif
                    </span>
                    <span class="min-w-0 pt-1">
                        <span class="block text-sm font-bold leading-5 {{ $stage['state'] === 'upcoming' ? 'text-fg-muted' : 'text-fg-strong' }}">{{ $stage['label'] }}</span>
                        @if($stage['state'] === 'complete' && ($stage['summary'] || in_array($stage['key'], ['learning', 'schedule'], true)))
                            <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-fg-muted">
                                @if($stage['summary'])
                                    <span class="truncate">{{ $stage['summary'] }}</span>
                                @endif
                                @if($current !== 'outcome')
                                    <button type="button" wire:click="editStage('{{ $stage['key'] }}')" class="min-h-6 rounded font-bold text-indigo-600 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 dark:text-indigo-300">
                                        Edit<span class="sr-only"> {{ $stage['label'] }}</span>
                                    </button>
                                @endif
                            </span>
                        @endif
                    </span>
                </div>
                @unless($isLast)
                    <span class="mx-3 mt-4 h-0.5 flex-1 rounded-full {{ $stage['state'] === 'complete' ? 'bg-emerald-400' : 'bg-edge' }}" aria-hidden="true"></span>
                @endunless
            </li>
        @endforeach
    </ol>
</nav>
