@props(['educations'])

@if($educations->isNotEmpty())
<x-account.card title="Education">
    <div class="space-y-6">
        @foreach($educations as $education)
            <div class="flex gap-4">
                <div class="w-12 h-12 rounded-xl overflow-hidden bg-surface-raised border border-edge flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-fg-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-fg-strong font-medium">{{ $education->degree ?? $education->education_level?->label() }}</p>
                            <p class="text-fg-muted text-sm">{{ $education->institution_name }}</p>
                        </div>
                        @if($education->is_current)
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/25 flex-shrink-0">Current</span>
                        @endif
                    </div>
                    <p class="text-fg-muted text-xs mt-1">
                        {{ $education->start_date->format('M Y') }} – {{ $education->is_current ? 'Present' : $education->end_date?->format('M Y') }}
                        @if($education->field_of_study)
                            · {{ $education->field_of_study }}
                        @endif
                    </p>
                    @if($education->description)
                        <p class="text-fg-muted text-sm mt-2">{{ $education->description }}</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-account.card>
@endif
