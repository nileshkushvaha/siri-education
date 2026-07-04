{{--
    Reusable text/email/password field for the dark auth-panel pages
    (login, register, forgot/reset password) — <x-ui.auth-input>.
    Wraps the existing .auth-input/.auth-label CSS (resources/css/app.css)
    so every auth page shares one implementation instead of each hand-
    rolling the same label+input+error markup. Pass wire:model directly;
    error display reads Livewire's own $errors bag by field name.

    Props:
        label, name, type (default: text), placeholder, hint
--}}
@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'placeholder' => null,
    'hint' => null,
])

@php
    $message = $name ? ($errors->first($name) ?? null) : null;
@endphp

<div>
    @if($label)
        <label for="{{ $name }}" class="auth-label">{{ $label }}</label>
    @endif

    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if($placeholder) placeholder="{{ $placeholder }}" @endif
        @if($message) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
        {{ $attributes->merge(['class' => 'auth-input'.($message ? ' error' : '')]) }}
    >

    @if($hint && ! $message)
        <p class="mt-1.5 text-xs text-slate-400">{{ $hint }}</p>
    @endif

    @if($message)
        <p id="{{ $name }}-error" class="mt-1.5 text-xs text-red-400 flex items-center gap-1" role="alert">
            <svg class="w-3.5 h-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            {{ $message }}
        </p>
    @endif
</div>
