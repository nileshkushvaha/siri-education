@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'error' => null,
    'disabled' => false,
])

@php
    $fieldId = $id ?? $name ?? 'input-'.Illuminate\Support\Str::uuid()->toString();
    $fieldValue = $name ? old($name, $value) : $value;
    $message = $error ?? ($name && isset($errors) ? $errors->first($name) : null);
    $descriptionId = $hint ? $fieldId.'-hint' : null;
    $errorId = $message ? $fieldId.'-error' : null;
@endphp

<div>
    @if($label)
        <label for="{{ $fieldId }}" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">{{ $label }}</label>
    @endif

    <input
        id="{{ $fieldId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ $fieldValue }}"
        @disabled($disabled)
        @if(filled($message)) aria-invalid="true" @endif
        @if(trim(($descriptionId ?? '').' '.($errorId ?? ''))) aria-describedby="{{ trim(($descriptionId ?? '').' '.($errorId ?? '')) }}" @endif
        {{ $attributes->merge([
            'class' => 'block min-h-10 w-full rounded-xl border bg-white px-3.5 py-2 text-sm text-slate-900 shadow-sm transition placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-indigo-400 dark:focus:ring-indigo-400/20 dark:disabled:bg-white/[0.03] '.($message ? 'border-red-300 focus:border-red-500 focus:ring-red-100 dark:border-red-400/40 dark:focus:border-red-400 dark:focus:ring-red-400/20' : 'border-slate-200'),
        ]) }}
    >

    @if($hint)
        <p id="{{ $descriptionId }}" class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
    @endif

    @if($message)
        <p id="{{ $errorId }}" class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-300">{{ $message }}</p>
    @endif
</div>
