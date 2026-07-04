{{--
    Small status/label pill — <x-ui.badge color="success">Active</x-ui.badge>
    Colors match the semantic palette already used across Filament
    widgets and the booking wizard, kept consistent for the new frontend.
--}}
@props(['color' => 'slate'])

@php
    $colors = [
        'slate' => 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-200',
        'indigo' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-400/15 dark:text-indigo-200',
        'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-200',
        'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-400/15 dark:text-amber-200',
        'danger' => 'bg-red-100 text-red-700 dark:bg-red-400/15 dark:text-red-200',
    ];
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold '.($colors[$color] ?? $colors['slate']),
]) }}>
    {{ $slot }}
</span>
