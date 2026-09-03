{{--
    One wizard step panel. Hidden unless the Alpine `step` matches.
    Props: step (number), title, subtitle (optional).
    Heading is focused on entry by the wizard's goTo() for screen readers.
--}}
@props(['step', 'title', 'subtitle' => null])

<section x-show="step === {{ $step }}"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         aria-labelledby="step-title-{{ $step }}"
         {{ $attributes }}>
    <h2 id="step-title-{{ $step }}" tabindex="-1"
        class="text-xl font-bold text-fg-strong focus:outline-none">{{ $title }}</h2>
    @if($subtitle)
        <p class="mt-1 text-sm text-fg-muted">{{ $subtitle }}</p>
    @endif

    <div class="mt-5">
        {{ $slot }}
    </div>
</section>
