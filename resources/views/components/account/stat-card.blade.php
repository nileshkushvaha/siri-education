@props([
    'label'    => '',
    'value'    => '0',
    'gradient' => 'from-indigo-500 to-violet-500',
    'icon'     => '',
])

<div class="relative overflow-hidden rounded-2xl border border-white/[0.075] p-5 group transition-all duration-300 hover:-translate-y-0.5" data-account-card>
    <div class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-500"
         style="background:radial-gradient(ellipse at top left, rgb(var(--portal-a) / .10), transparent 70%)"></div>
    <div class="relative z-10">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br {{ $gradient }} flex items-center justify-center mb-4 shadow-lg">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="{{ $icon }}"/>
            </svg>
        </div>
        <p class="text-3xl font-bold text-white mb-1">{{ $value }}</p>
        <p class="text-slate-400 text-sm">{{ $label }}</p>
    </div>
</div>
