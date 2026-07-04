@props(['icon', 'title', 'message'])

<div class="flex flex-col items-center justify-center py-16 text-center">
    <div class="w-16 h-16 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center mb-4">
        <svg class="w-8 h-8 text-indigo-400/60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $icon }}"/>
        </svg>
    </div>
    <h3 class="text-slate-300 font-semibold mb-2">{{ $title }}</h3>
    <p class="text-slate-400 text-sm max-w-xs">{{ $message }}</p>
</div>
