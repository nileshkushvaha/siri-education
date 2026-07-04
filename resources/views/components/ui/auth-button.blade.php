{{--
    Reusable submit button for auth forms — <x-ui.auth-button wire:loading.attr="disabled">
    Wraps .auth-btn-primary with a built-in Livewire loading spinner so
    every form gets the same "busy" state without repeating the markup.

    Props: loadingText (shown while the form is submitting)
--}}
@props(['loadingText' => 'Please wait…'])

<button type="submit" {{ $attributes->merge(['class' => 'auth-btn-primary']) }}>
    <svg wire:loading class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
    </svg>
    <span wire:loading>{{ $loadingText }}</span>
    <span wire:loading.remove class="flex items-center gap-2">{{ $slot }}</span>
</button>
