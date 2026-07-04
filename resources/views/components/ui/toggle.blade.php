@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'checked' => false,
    'hint' => null,
    'disabled' => false,
])

@php
    $fieldId = $id ?? $name ?? 'toggle-'.Illuminate\Support\Str::uuid()->toString();
    $isChecked = $name ? old($name, $checked) : $checked;
@endphp

<div class="flex items-start justify-between gap-4">
    <div class="min-w-0">
        @if($label)
            <label for="{{ $fieldId }}" class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $label }}</label>
        @endif
        @if($hint)
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
        @endif
    </div>
    <label class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center">
        <input id="{{ $fieldId }}" name="{{ $name }}" type="checkbox" value="1" class="peer sr-only" @checked($isChecked) @disabled($disabled) {{ $attributes }}>
        <span class="h-6 w-11 rounded-full bg-slate-200 transition peer-checked:bg-indigo-600 peer-focus-visible:ring-4 peer-focus-visible:ring-indigo-100 peer-disabled:cursor-not-allowed peer-disabled:opacity-50 dark:bg-white/15 dark:peer-checked:bg-indigo-500 dark:peer-focus-visible:ring-indigo-400/20"></span>
        <span class="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow-sm transition peer-checked:translate-x-5"></span>
    </label>
</div>
