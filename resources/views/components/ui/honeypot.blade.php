{{--
    Spam-trap field — invisible to sighted users, skipped by screen
    readers, but visible to most bots that blindly fill every input.
    <x-ui.honeypot wire:model="website" /> — bind to a Livewire property
    validated with ['prohibited'] (must stay empty).

    Off-screen rather than display:none/visibility:hidden, since some
    bots skip fields hidden that way but still fill absolutely
    positioned ones — this trap only works if it "looks" fillable.
--}}
@props(['name' => 'website', 'label' => 'Website'])

<div class="absolute -left-[9999px] top-auto h-px w-px overflow-hidden" aria-hidden="true">
    <label for="{{ $name }}-hp">{{ $label }}</label>
    <input id="{{ $name }}-hp" type="text" tabindex="-1" autocomplete="off" {{ $attributes }}>
</div>
