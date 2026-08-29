{{-- CMS Hero Carousel Block — a fixed headline frame whose middle line rotates while the
     subject photography, badge card, highlight pills and name pill cross-fade behind it.

     Photography spec (see public/images/hero/README.md): a transparent-background WebP
     cutout of the subject, waist-up, facing camera, roughly 1400x1600. The subject is
     anchored to the bottom-right and deliberately bleeds off the frame. Slides without a
     photo simply render the headline over the gradient — nothing breaks. --}}
@php
    $slides = $slides ?? [];

    // Homepage templates include this view with use_default_slides to get a sensible fold
    // before an administrator has authored a Hero Carousel block.
    if (empty($slides) && ($use_default_slides ?? false)) {
        $prefix_text = $prefix_text ?? 'Learn faster with';
        $suffix_text = $suffix_text ?? 'from verified expert tutors.';
        $footnote = $footnote ?? 'Trusted by 10,000+ students worldwide';

        $slides = [
            [
                'tab_label' => 'One-to-One Tutoring',
                'rotating_text' => 'personalised 1-on-1 lessons',
                'image' => '/images/hero/student-one-to-one.webp',
                'badge_title' => 'One-to-One Classes',
                'badge_subtitle' => 'Powered by '.$appName,
                'highlights' => ['Doubts Cleared', 'Homework Supported', 'Progress Tracked'],
                'primary_button_text' => 'Find a Tutor',
                'primary_button_link' => route('auth.register'),
                'secondary_button_text' => 'See how it works',
                'secondary_button_link' => '#how-it-works',
            ],
            [
                'tab_label' => 'Exam Preparation',
                'rotating_text' => 'focused competitive exam prep',
                'image' => '/images/hero/student-exam-prep.webp',
                'badge_title' => 'Exam Preparation',
                'badge_subtitle' => 'Powered by '.$appName,
                'highlights' => ['Mock Tests', 'Weak Areas Fixed', 'Scores Improved'],
                'primary_button_text' => 'Explore Instructors',
                'primary_button_link' => route('instructors.index'),
                'secondary_button_text' => 'See how it works',
                'secondary_button_link' => '#how-it-works',
            ],
            [
                'tab_label' => 'Flexible Scheduling',
                'rotating_text' => 'lessons that fit your timezone',
                'image' => '/images/hero/student-flexible.webp',
                'badge_title' => 'Flexible Scheduling',
                'badge_subtitle' => 'Powered by '.$appName,
                'highlights' => ['Your Timezone', 'Reschedule Free', 'Evening Slots'],
                'primary_button_text' => 'Book a Free Demo',
                'primary_button_link' => Route::has('booking.create') ? route('booking.create') : route('auth.register'),
                'secondary_button_text' => 'See how it works',
                'secondary_button_link' => '#how-it-works',
            ],
            [
                'tab_label' => 'Homework Support',
                'rotating_text' => 'homework help when it matters',
                'image' => '/images/hero/student-homework.webp',
                'badge_title' => 'Homework Support',
                'badge_subtitle' => 'Powered by '.$appName,
                'highlights' => ['Same-Day Answers', 'Step-by-Step Working', 'Marked Feedback'],
                'primary_button_text' => 'Get Homework Help',
                'primary_button_link' => route('auth.register'),
                'secondary_button_text' => 'See how it works',
                'secondary_button_link' => '#how-it-works',
            ],
            [
                'tab_label' => 'Progress Tracking',
                'rotating_text' => 'progress you can actually see',
                'image' => '/images/hero/student-progress.webp',
                'badge_title' => 'Progress Tracking',
                'badge_subtitle' => 'Powered by '.$appName,
                'highlights' => ['Goals Set', 'Reports Shared', 'Parents Updated'],
                'primary_button_text' => 'Start Learning',
                'primary_button_link' => route('auth.register'),
                'secondary_button_text' => 'See how it works',
                'secondary_button_link' => '#how-it-works',
            ],
        ];
    }

    $slides = array_values(array_filter($slides, fn ($slide) => filled($slide['rotating_text'] ?? null)));
    $slideCount = count($slides);

    $prefixText = $prefix_text ?? '';
    $suffixText = $suffix_text ?? '';
    $footnoteText = $footnote ?? '';
    $autoplayEnabled = (bool) ($autoplay ?? true);
    $rotationInterval = (int) ($interval ?? 5000);
    $showArrows = (bool) ($show_arrows ?? true) && $slideCount > 1;

    // Uploaded images are stored as disk paths; absolute and root-relative values pass
    // through. A root-relative path that is not on disk yet resolves to null so the hero
    // degrades to its gradient instead of rendering a broken image.
    $resolveImage = function (?string $path): ?string {
        if (blank($path)) {
            return null;
        }

        if (\Illuminate\Support\Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        if (\Illuminate\Support\Str::startsWith($path, '/')) {
            return is_file(public_path(ltrim($path, '/'))) ? $path : null;
        }

        // Matches the disk pinned on the block's FileUpload. The default disk is `local`
        // (storage/app/private) and would not produce a web-servable URL.
        return \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    };

    $slides = array_map(function (array $slide) use ($resolveImage) {
        $slide['resolved_image'] = $resolveImage($slide['image'] ?? null);

        return $slide;
    }, $slides);

    $hasAnyPhoto = collect($slides)->contains(fn ($slide) => filled($slide['resolved_image']));
@endphp

@if($slideCount > 0)
<section
    class="hero-carousel relative isolate overflow-hidden bg-surface-dark"
    x-data="{
        active: 0,
        count: {{ $slideCount }},
        timer: null,
        autoplay: {{ $autoplayEnabled ? 'true' : 'false' }},
        interval: {{ max(2000, $rotationInterval) }},
        reduced: false,
        init() {
            this.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            this.start();
        },
        destroy() {
            this.stop();
        },
        start() {
            this.stop();
            if (! this.autoplay || this.reduced || this.count < 2) return;
            this.timer = setInterval(() => { this.active = (this.active + 1) % this.count }, this.interval);
        },
        stop() {
            if (this.timer) { clearInterval(this.timer); this.timer = null }
        },
        go(index) {
            this.active = ((index % this.count) + this.count) % this.count;
            this.start();
        },
        next() { this.go(this.active + 1) },
        prev() { this.go(this.active - 1) },
    }"
    @mouseenter="stop()"
    @mouseleave="start()"
    @focusin="stop()"
    @focusout="start()"
    aria-roledescription="carousel"
    aria-label="Featured highlights"
>
    {{-- Ambient base --}}
    <div class="hero-mesh absolute inset-0 -z-30" aria-hidden="true"></div>

    @if($hasAnyPhoto)
        {{-- Subject stage: the diagonal light wedge and the cutout photo. Sits below the
             scrim so the scrim can darken the photo where it meets the headline. The
             overlays live in their own layer above the scrim — see below. Hidden below lg,
             where there is no room for a subject beside the headline. --}}
        <div class="pointer-events-none absolute inset-y-0 right-0 -z-20 hidden w-[62%] lg:block" aria-hidden="true">

            {{-- Diagonal white light wedge behind the subject --}}
            <div
                class="absolute inset-0 [clip-path:polygon(38%_0,100%_0,100%_100%,0_100%)]"
                style="background: linear-gradient(202deg, rgba(255,255,255,0.94) 0%, rgba(224,235,255,0.72) 34%, rgba(150,165,255,0.28) 62%, rgba(10,14,32,0) 88%);"
            ></div>

            {{-- Cool arc that grounds the wedge, as on the reference --}}
            <div
                class="absolute -left-24 bottom-0 h-[78%] w-[52%] rounded-full opacity-70 blur-2xl"
                style="background: radial-gradient(circle at 40% 60%, rgba(59,130,246,0.55), rgba(99,102,241,0.18) 45%, transparent 70%);"
            ></div>

            @foreach($slides as $index => $slide)
                @if($slide['resolved_image'])
                    <img
                        src="{{ $slide['resolved_image'] }}"
                        alt=""
                        class="absolute bottom-0 right-0 h-[94%] w-full object-contain object-bottom transition-opacity duration-700 ease-out motion-reduce:transition-none"
                        style="{{ $index === 0 ? 'opacity:1' : 'opacity:0' }}"
                        :style="active === {{ $index }} ? 'opacity:1' : 'opacity:0'"
                        loading="{{ $index === 0 ? 'eager' : 'lazy' }}"
                        decoding="async"
                    >
                @endif
            @endforeach
        </div>

        {{-- Scrim keeps the headline legible where it meets the wedge --}}
        <div class="pointer-events-none absolute inset-0 -z-10 hidden lg:block" aria-hidden="true"
             style="background: linear-gradient(90deg, rgba(5,8,15,0.97) 0%, rgba(5,8,15,0.9) 38%, rgba(5,8,15,0.35) 55%, rgba(5,8,15,0) 68%);"></div>

        {{-- Overlay layer: above the scrim, so the badge, highlight pills and
             name pill keep full contrast instead of being dimmed by it. --}}
        <div class="pointer-events-none absolute inset-y-0 right-0 z-0 hidden w-[62%] lg:block" aria-hidden="true">
            @foreach($slides as $index => $slide)
                @if($slide['resolved_image'])
                    <div
                        class="absolute inset-0 transition-opacity duration-700 ease-out motion-reduce:transition-none"
                        style="{{ $index === 0 ? 'opacity:1' : 'opacity:0' }}"
                        :style="active === {{ $index }} ? 'opacity:1' : 'opacity:0'"
                    >
                        @if(filled($slide['badge_title'] ?? null))
                            <div class="absolute right-[6%] top-[14%] rounded-2xl px-7 py-5 text-center shadow-2xl shadow-indigo-950/40"
                                 style="background: linear-gradient(135deg, rgba(99,102,241,0.96), rgba(79,70,229,0.92));">
                                <p class="text-lg font-semibold tracking-[-0.01em] text-white">{{ $slide['badge_title'] }}</p>
                                @if(filled($slide['badge_subtitle'] ?? null))
                                    <p class="mt-1.5 text-[11px] font-semibold uppercase tracking-[0.18em] text-indigo-100">{{ $slide['badge_subtitle'] }}</p>
                                @endif
                            </div>
                        @endif

                        @foreach(array_slice($slide['highlights'] ?? [], 0, 3) as $pillIndex => $highlight)
                            <div
                                class="absolute rounded-full bg-white/85 px-7 py-3.5 text-base font-medium text-slate-800 shadow-xl shadow-slate-950/20 backdrop-blur-sm"
                                style="right: {{ [4, 8, 2][$pillIndex] }}%; top: {{ [46, 60, 74][$pillIndex] }}%;"
                            >{{ $highlight }}</div>
                        @endforeach

                    </div>
                @endif
            @endforeach
        </div>
    @endif

    {{-- 65vh is the desktop target; smaller viewports step down so the fold never
         squeezes the headline, and dvh keeps mobile stable as browser chrome hides. --}}
    <div class="relative mx-auto flex min-h-[55dvh] max-w-7xl flex-col justify-center px-4 py-12 sm:min-h-[60dvh] sm:px-6 sm:py-14 lg:min-h-[65vh] lg:px-8">
        <div class="{{ $hasAnyPhoto ? 'lg:max-w-[62%]' : '' }}">

            {{-- Headline: the outer sentence holds still, only the middle line travels.
                 Type matches the Razorpay hero — display size 48px at 1.15 line-height,
                 weight 500, letter-spacing -1px (-0.02em), solid white with no gradient. --}}
            <h1 class="max-w-4xl text-3xl font-medium leading-[1.15] tracking-[-0.02em] text-white sm:text-4xl lg:text-5xl">
                {{-- Two-tone headline, mirroring the Razorpay hero's brand-blue + neutral
                     split. On our dark fold the neutral is white rather than near-black,
                     and the brand tone is the site's own indigo/violet/pink gradient. --}}
                @if($prefixText)
                    <span class="block lg:whitespace-nowrap">{{ $prefixText }}</span>
                @endif

                {{-- Every line shares one grid cell, so the frame sizes itself to the tallest
                     line and no line is ever clipped by a fixed height. --}}
                <span class="grid overflow-hidden" aria-live="polite">
                    @foreach($slides as $index => $slide)
                        {{-- State is driven entirely through inline style: the pre-boot style
                             attribute and Alpine's :style write to the same channel, so neither
                             can outrank the other. Toggling utility classes here would lose to
                             the inline attribute and pin the line transparent forever. --}}
                        <span
                            class="text-grad col-start-1 row-start-1 transition-all duration-500 ease-out motion-reduce:transition-none lg:whitespace-nowrap"
                            style="{{ $index === 0 ? 'opacity:1' : 'opacity:0;transform:translateY(100%)' }}"
                            :style="active === {{ $index }} ? 'opacity:1;transform:translateY(0)' : 'opacity:0;transform:translateY(100%)'"
                        >{{ $slide['rotating_text'] }}</span>
                    @endforeach
                </span>

                @if($suffixText)
                    <span class="text-grad-cyan block lg:whitespace-nowrap">{{ $suffixText }}</span>
                @endif
            </h1>

            {{-- Per-slide calls to action --}}
            <div class="mt-8 min-h-[3.5rem]">
                @foreach($slides as $index => $slide)
                    {{-- Inline display keeps the first slide's buttons visible before Alpine boots --}}
                    <div class="flex flex-wrap gap-4" x-show="active === {{ $index }}" @style(['display:none' => $index !== 0])>
                        @if(filled($slide['primary_button_text'] ?? null) && filled($slide['primary_button_link'] ?? null))
                            <a href="{{ $slide['primary_button_link'] }}"
                               class="btn-amber rounded-2xl px-8 py-4 text-base font-bold text-white shadow-xl"
                               :tabindex="active === {{ $index }} ? 0 : -1">
                                {{ $slide['primary_button_text'] }}
                            </a>
                        @endif

                        @if(filled($slide['secondary_button_text'] ?? null) && filled($slide['secondary_button_link'] ?? null))
                            <a href="{{ $slide['secondary_button_link'] }}"
                               class="glass-md rounded-2xl px-8 py-4 text-base font-semibold text-white transition-colors hover:bg-white/15"
                               :tabindex="active === {{ $index }} ? 0 : -1">
                                {{ $slide['secondary_button_text'] }}
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Pill tabs double as the slide indicators --}}
            <div class="mt-8 flex flex-wrap items-center gap-2.5" role="tablist" aria-label="Choose a highlight">
                @foreach($slides as $index => $slide)
                    <button
                        type="button"
                        role="tab"
                        @click="go({{ $index }})"
                        :aria-selected="active === {{ $index }} ? 'true' : 'false'"
                        :class="active === {{ $index }}
                            ? 'bg-indigo-500/25 border-indigo-500/60 text-indigo-200 shadow-lg shadow-indigo-500/10'
                            : 'text-gray-400 hover:text-gray-200 hover:border-white/25'"
                        class="glass flex items-center gap-1.5 whitespace-nowrap rounded-xl border border-white/10 px-4 py-2 text-sm font-medium transition-all duration-200"
                    >
                        {{ $slide['tab_label'] ?? 'Slide '.($index + 1) }}
                    </button>
                @endforeach

                @if($showArrows)
                    <div class="ml-auto flex items-center gap-2">
                        <button type="button" @click="prev()" aria-label="Previous slide"
                                class="glass flex h-10 w-10 items-center justify-center rounded-full border border-white/10 text-white transition-colors hover:bg-white/15">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <button type="button" @click="next()" aria-label="Next slide"
                                class="glass flex h-10 w-10 items-center justify-center rounded-full border border-white/10 text-white transition-colors hover:bg-white/15">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                @endif
            </div>

            @if($footnoteText)
                <p class="mt-5 text-xs text-gray-400">{{ $footnoteText }}</p>
            @endif
        </div>
    </div>
</section>
@endif
