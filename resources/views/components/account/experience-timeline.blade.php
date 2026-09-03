@props(['experiences'])

@if($experiences->isNotEmpty())
<x-account.card title="Experience">
    <div class="space-y-6">
        @foreach($experiences as $experience)
            <div class="flex gap-4">
                <div class="w-12 h-12 rounded-xl overflow-hidden bg-surface-raised border border-edge flex items-center justify-center flex-shrink-0">
                    @if($experience->companyLogoThumbUrl)
                        <img src="{{ $experience->companyLogoThumbUrl }}" class="w-full h-full object-cover" alt="{{ $experience->organization_name }}">
                    @else
                        <svg class="w-5 h-5 text-fg-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                    @endif
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-fg-strong font-medium">{{ $experience->designation }}</p>
                            <p class="text-fg-muted text-sm">{{ $experience->organization_name }}</p>
                        </div>
                        @if($experience->is_current)
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/25 flex-shrink-0">Current</span>
                        @endif
                    </div>
                    <p class="text-fg-muted text-xs mt-1">
                        {{ $experience->start_date->format('M Y') }} – {{ $experience->is_current ? 'Present' : $experience->end_date?->format('M Y') }}
                        @if($experience->location)
                            · {{ $experience->location }}
                        @endif
                    </p>
                    @if($experience->description)
                        <p class="text-fg-muted text-sm mt-2">{{ $experience->description }}</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-account.card>
@endif
