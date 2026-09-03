@props([
    'title'    => null,
    'linkText' => null,
    'linkHref' => null,
])

<div {{ $attributes->class(['relative overflow-hidden rounded-2xl border border-edge p-5 sm:p-6 group transition-all duration-300'])->merge(['data-account-card' => '']) }}>

    <div class="pointer-events-none absolute -right-8 -top-10 h-28 w-28 rounded-full bg-[rgb(var(--portal-c)/.045)] blur-2xl transition-opacity group-hover:opacity-150" aria-hidden="true"></div>

    @if($title)
        <div class="relative flex items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-2.5">
                <span class="h-2 w-2 rounded-full bg-[rgb(var(--portal-a))] shadow-[0_0_14px_rgb(var(--portal-a)/.65)]" aria-hidden="true"></span>
                <h2 class="text-lg font-semibold text-fg-strong">{{ $title }}</h2>
            </div>
            @if($linkText && $linkHref)
                <a href="{{ $linkHref }}" class="text-xs font-semibold text-[rgb(var(--portal-a))] transition hover:text-fg-strong">{{ $linkText }}</a>
            @endif
        </div>
    @endif

    <div class="relative">{{ $slot }}</div>
</div>
