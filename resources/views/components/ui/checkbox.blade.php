@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'value' => '1',
    'checked' => false,
    'hint' => null,
    'disabled' => false,
])

@php
    $fieldId = $id ?? $name ?? 'checkbox-'.Illuminate\Support\Str::uuid()->toString();
    $isChecked = $name ? old($name, $checked) : $checked;
@endphp

<div class="flex items-start gap-3">
    <input
        id="{{ $fieldId }}"
        name="{{ $name }}"
        type="checkbox"
        value="{{ $value }}"
        @checked($isChecked)
        @disabled($disabled)
        {{ $attributes->merge(['class' => 'mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-4 focus:ring-indigo-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/20 dark:bg-white/5 dark:focus:ring-indigo-400/20']) }}
    >
    <div class="min-w-0">
        @if($label)
            <label for="{{ $fieldId }}" class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $label }}</label>
        @endif
        @if($hint)
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
        @endif
    </div>
</div>
