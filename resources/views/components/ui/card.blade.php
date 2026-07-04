{{--
    Generic content card — <x-ui.card>...</x-ui.card>
    Presentation-only; content-agnostic like x-booking.option-card.
    Pass `padded="false"` to remove default inner padding when the
    slot content needs to touch the card edges (e.g. an image).
--}}
@props(['padded' => true])

<div {{ $attributes->merge([
    'class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04] '.($padded ? 'p-5 sm:p-6' : ''),
]) }}>
    {{ $slot }}
</div>
