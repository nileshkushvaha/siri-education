{{--
    SIRI Education brand logo — inline SVG so the wordmark uses the page's
    self-hosted Inter and the colours can follow the active theme.

    variant: light (navy wordmark, for light surfaces)
             dark  (white wordmark, for dark surfaces)
             auto  (follows the `html.dark` class — Account Portal pages)
    mark:    true renders only the pen-nib symbol (square 264×264 viewBox)

    Standalone copies for <img>/email/favicon use live in public/images/brand/.
--}}
@props([
    'variant' => 'light',
    'mark' => false,
    'label' => null,
])

@php
    $label = $label ?? (app(\App\Settings\GeneralSettings::class)->app_name ?: config('app.name'));
    $gid = 'siri-nib-'.uniqid();
    $navy = '#07142f';
    $gold = '#eba73b';
    $font = "Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif";

    // Slit is white on light surfaces and navy on dark ones; the wordmark inverts.
    [$slitAttr, $wordAttr] = match ($variant) {
        'dark' => ['fill="'.$navy.'"', 'fill="#ffffff"'],
        'auto' => ['class="fill-white dark:fill-[#07142f]"', 'class="fill-[#07142f] dark:fill-white"'],
        default => ['fill="#ffffff"', 'fill="'.$navy.'"'],
    };
@endphp

<svg {{ $attributes->merge(['class' => 'block h-10 w-auto']) }}
     xmlns="http://www.w3.org/2000/svg"
     viewBox="{{ $mark ? '0 0 264 264' : '0 0 680 264' }}"
     role="img"
     aria-label="{{ $label }}">
    <defs>
        <linearGradient id="{{ $gid }}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#7ee8ff"/>
            <stop offset=".22" stop-color="#38bdfc"/>
            <stop offset=".55" stop-color="#3049f6"/>
            <stop offset="1" stop-color="#7a12fd"/>
        </linearGradient>
    </defs>
    <g @if($mark) transform="translate(72 0)" @endif>
        <path d="M60 0 L118 136 Q120 141 118.5 146 C112 172 95 205 91 238 Q90.5 240 88 240 L32 240 Q29.5 240 29 238 C25 205 8 172 1.5 146 Q0 141 2 136 Z" fill="url(#{{ $gid }})"/>
        <rect x="57.75" y="10" width="4.5" height="124" rx="2.25" {!! $slitAttr !!}/>
        <circle cx="60" cy="144.5" r="13.5" fill="{{ $gold }}"/>
        <rect x="14" y="250" width="92" height="14" rx="7" fill="{{ $gold }}"/>
    </g>
    @unless($mark)
        <text x="166" y="190" font-family="{{ $font }}" font-weight="900" font-size="175" textLength="511" lengthAdjust="spacing" {!! $wordAttr !!}>SIRI</text>
        <text x="166" y="256" font-family="{{ $font }}" font-weight="700" font-size="46" textLength="511" lengthAdjust="spacing" fill="{{ $gold }}">EDUCATION</text>
    @endunless
</svg>
