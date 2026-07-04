@props([
    'title' => 'Nothing here yet',
    'description' => null,
])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-dashed border-slate-300 bg-white/70 px-6 py-10 text-center dark:border-white/15 dark:bg-white/[0.03]']) }}>
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-300">
        @isset($icon)
            {{ $icon }}
        @else
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5v9.75A2.25 2.25 0 0118 19.5H6a2.25 2.25 0 01-2.25-2.25V7.5m16.5 0A2.25 2.25 0 0018 5.25H6A2.25 2.25 0 003.75 7.5m16.5 0v.243a2.25 2.25 0 01-.659 1.591l-5.25 5.25a2.25 2.25 0 01-3.182 0l-5.25-5.25a2.25 2.25 0 01-.659-1.591V7.5"/></svg>
        @endisset
    </div>
    <h3 class="mt-4 text-base font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>
    @if($description)
        <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">{{ $description }}</p>
    @endif
    @if(trim((string) $slot) !== '')
        <div class="mt-6 flex justify-center">{{ $slot }}</div>
    @endif
</div>
