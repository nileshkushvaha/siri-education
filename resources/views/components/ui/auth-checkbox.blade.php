{{--
    Reusable dark-themed checkbox for auth pages (remember me, accept
    terms) — <x-ui.auth-checkbox>. Pass wire:model directly; error
    display reads Livewire's own $errors bag by field name.

    Label content: pass plain text via the `label` prop (HTML-escaped,
    safe for dynamic content), or rich markup — e.g. embedded links,
    as the "terms of service" checkbox needs — via the default slot
    (rendered raw; only ever developer-authored Blade, never user input).
--}}
@props(['label' => null, 'name' => null])

@php
    $message = $name ? ($errors->first($name) ?? null) : null;
@endphp

<div>
    <label class="flex items-start gap-2.5 cursor-pointer select-none">
        <input
            id="{{ $name }}"
            name="{{ $name }}"
            type="checkbox"
            value="1"
            {{ $attributes->merge(['class' => 'mt-0.5 h-4 w-4 rounded border-white/20 bg-white/5 text-indigo-600 focus:ring-4 focus:ring-indigo-500/20 focus:outline-none']) }}
        >
        @if($slot->isNotEmpty())
            <span class="text-sm text-slate-400 leading-snug">{!! $slot !!}</span>
        @elseif($label)
            <span class="text-sm text-slate-400">{{ $label }}</span>
        @endif
    </label>

    @if($message)
        <p class="mt-1.5 text-xs text-red-400" role="alert">{{ $message }}</p>
    @endif
</div>
