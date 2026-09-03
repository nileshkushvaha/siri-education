@props(['percentage', 'breakdown' => []])

@php
    $labels = [
        'basic_profile' => 'Basic profile',
        'avatar' => 'Profile photo',
        'bio' => 'Bio',
        'experience' => 'Work experience',
        'education' => 'Education',
        'social_links' => 'Social links',
    ];
    $missing = collect($breakdown)->filter(fn ($section) => $section['score'] < 1.0)->keys();
@endphp

<div class="rounded-2xl border border-edge bg-surface-raised backdrop-blur-xl p-6" data-account-profile-completion>
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-fg-strong">Profile Completion</h3>
        <span class="text-lg font-bold {{ $percentage >= 100 ? 'text-emerald-600 dark:text-emerald-400' : 'text-indigo-600 dark:text-indigo-300' }}">{{ $percentage }}%</span>
    </div>
    <div class="h-2 bg-surface-raised rounded-full overflow-hidden mb-4">
        <div class="h-full bg-gradient-to-r from-indigo-500 to-violet-500 rounded-full transition-all" style="width: {{ $percentage }}%"></div>
    </div>

    @if($missing->isNotEmpty())
        <p class="text-xs text-fg-muted mb-2">Still missing:</p>
        <div class="flex flex-wrap gap-1.5">
            @foreach($missing as $key)
                <span class="px-2 py-1 rounded-lg text-[11px] font-medium bg-amber-500/10 text-amber-600 dark:text-amber-300 border border-amber-500/20">
                    {{ $labels[$key] ?? ucwords(str_replace('_', ' ', $key)) }}
                </span>
            @endforeach
        </div>
    @else
        <p class="text-xs text-emerald-600 dark:text-emerald-400">Your profile is complete!</p>
    @endif
</div>
