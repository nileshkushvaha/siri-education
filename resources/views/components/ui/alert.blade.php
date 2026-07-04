{{--
    Generic inline alert/banner — <x-ui.alert type="success">Saved!</x-ui.alert>
    General-purpose version of the pattern already used ad hoc for
    session flash messages (layouts/frontend.blade.php) and the guest
    booking wizard (x-booking.alert). Use this for any new static or
    Livewire-driven banner; it carries no state of its own.

    Props:
        type: success | error | warning | info  (default: info)
--}}
@props(['type' => 'info'])

@php
    $palette = [
        'success' => ['bg-emerald-50 border-emerald-200 text-emerald-700 dark:bg-emerald-400/10 dark:border-emerald-400/20 dark:text-emerald-200', 'M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z'],
        'error' => ['bg-red-50 border-red-200 text-red-700 dark:bg-red-400/10 dark:border-red-400/20 dark:text-red-200', 'M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z'],
        'warning' => ['bg-amber-50 border-amber-200 text-amber-700 dark:bg-amber-400/10 dark:border-amber-400/20 dark:text-amber-200', 'M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z'],
        'info' => ['bg-blue-50 border-blue-200 text-blue-700 dark:bg-blue-400/10 dark:border-blue-400/20 dark:text-blue-200', 'M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z'],
    ];

    [$classes, $iconPath] = $palette[$type] ?? $palette['info'];
@endphp

<div role="alert" {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-xl border px-4 py-3 text-sm '.$classes]) }}>
    <svg class="h-5 w-5 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
        <path fill-rule="evenodd" d="{{ $iconPath }}" clip-rule="evenodd"/>
    </svg>
    <div class="flex-1">{{ $slot }}</div>
</div>
