@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => null,
    'hint' => null,
    'error' => null,
    'disabled' => false,
])

@php
    $fieldId = $id ?? $name ?? 'select-'.Illuminate\Support\Str::uuid()->toString();
    $value = $name ? old($name, $selected) : $selected;
    $message = $error ?? ($name && isset($errors) ? $errors->first($name) : null);
    $descriptionId = $hint ? $fieldId.'-hint' : null;
    $errorId = $message ? $fieldId.'-error' : null;
@endphp

<div>
    @if($label)
        <label for="{{ $fieldId }}" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">{{ $label }}</label>
    @endif

    <select
        id="{{ $fieldId }}"
        name="{{ $name }}"
        @disabled($disabled)
        @if(filled($message)) aria-invalid="true" @endif
        @if(trim(($descriptionId ?? '').' '.($errorId ?? ''))) aria-describedby="{{ trim(($descriptionId ?? '').' '.($errorId ?? '')) }}" @endif
        {{ $attributes->merge([
            'class' => 'block min-h-10 w-full rounded-xl border bg-white px-3.5 py-2 text-sm text-slate-900 shadow-sm transition focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100 dark:focus:border-indigo-400 dark:focus:ring-indigo-400/20 dark:disabled:bg-white/[0.03] '.($message ? 'border-red-300 focus:border-red-500 focus:ring-red-100 dark:border-red-400/40 dark:focus:border-red-400 dark:focus:ring-red-400/20' : 'border-slate-200'),
        ]) }}
    >
        @if($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif

        @forelse($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
        @empty
            {{ $slot }}
        @endforelse
    </select>

    @if($hint)
        <p id="{{ $descriptionId }}" class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
    @endif

    @if($message)
        <p id="{{ $errorId }}" class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-300">{{ $message }}</p>
    @endif
</div>
