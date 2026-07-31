@extends('layouts.frontend')

@php
    $primarySubjectForSeo = $subjects->first();
    $seoTitleSuffix = $primarySubjectForSeo ? $primarySubjectForSeo['name'] . ' Tutor' : 'Instructor';
@endphp

@section('title', $instructor->name . ' - ' . $seoTitleSuffix . ' — ' . config('app.name'))

@push('meta')
    <meta name="description" content="{{ $summaryText ?: ($instructor->name . ' is an instructor on ' . config('app.name')) }}">
    <meta property="og:title" content="{{ $instructor->name }} — Instructor">
    <meta property="og:description" content="{{ $biographyText ? Str::limit($biographyText, 200) : ($instructor->name . ' is an instructor on ' . config('app.name')) }}">
    <meta property="og:type" content="profile">
    <meta property="og:url" content="{{ route('instructors.show', $instructor) }}">
    @if($profile->profile_visibility !== 'public' || ! in_array($profile->instructor_status?->value, \App\Enums\InstructorStatus::bookableValues(), true))
        <meta name="robots" content="noindex, nofollow">
    @endif
    @if($profile->avatarUrl)
        <meta property="og:image" content="{{ $profile->avatarUrl }}">
    @endif
    <link rel="canonical" href="{{ route('instructors.show', $instructor) }}">
@endpush

@push('structured_data')
@php
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        'name' => $instructor->name,
        'url' => route('instructors.show', $instructor),
    ];
    if ($biographyText) {
        $jsonLd['description'] = Str::limit(strip_tags($biographyText), 500);
    }
    if ($profile->avatarUrl) {
        $jsonLd['image'] = $profile->avatarUrl;
    }
    if ($currentPosition) {
        $jsonLd['jobTitle'] = $currentPosition->designation;
    }

    // AggregateRating/Review only when reviews actually exist;
    // never a fabricated "0 reviews, 5 stars" block. $reviewSummary and
    // $reviews come from PublicInstructorReviewService — already filtered
    // to published, eligible, public reviews only (see InstructorController).
    if ($reviewSummary->reviewCount > 0 && $reviewSummary->averageRating !== null) {
        $jsonLd['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => number_format($reviewSummary->averageRating, 1),
            'reviewCount' => $reviewSummary->reviewCount,
        ];

        $jsonLd['review'] = $reviews->getCollection()->map(fn ($review): array => [
            '@type' => 'Review',
            'author' => ['@type' => 'Person', 'name' => $review->reviewerLabel],
            'datePublished' => $review->submittedAt->toDateString(),
            'reviewRating' => [
                '@type' => 'Rating',
                'ratingValue' => $review->overallRating,
                'bestRating' => 5,
                'worstRating' => 1,
            ],
            ...($review->content ? ['reviewBody' => Str::limit(strip_tags($review->content), 500)] : []),
        ])->values()->all();
    }
@endphp
<script type="application/ld+json">{{ json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</script>
@endpush

@section('content')
@php
    $primarySubject = $subjects->first();
    $demoBookingUrl = route('booking.create', array_filter([
        'type' => 'free_demo',
        'instructor' => $instructor->slug,
        'subject' => $primarySubject['booking_value'] ?? null,
    ]));
    $paidBookingUrl = route('booking.create', array_filter([
        'type' => 'paid_one_to_one',
        'instructor' => $instructor->slug,
        'subject' => $primarySubject['booking_value'] ?? null,
    ]));
@endphp

<div class="min-h-screen bg-white text-slate-900" data-instructor-profile>
    <section class="relative overflow-hidden border-b border-white/10 bg-[#080b24] text-white">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-indigo-400 via-emerald-300 to-fuchsia-400" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -left-32 top-0 h-96 w-96 rounded-full bg-cyan-400/20 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute left-[45%] top-12 h-72 w-72 rounded-full bg-violet-500/20 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -right-32 bottom-0 h-[28rem] w-[28rem] rounded-full bg-fuchsia-500/20 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-0 opacity-20" style="background-image:radial-gradient(#a5b4fc .7px,transparent .7px);background-size:24px 24px" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-14">
            <x-ui.breadcrumb :items="[
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Instructors', 'url' => route('instructors.index')],
                ['label' => $instructor->name],
            ]" />

            <div class="mt-9 grid gap-8 lg:grid-cols-3 lg:items-end" data-profile-reveal>
                <div class="min-w-0 lg:col-span-2">
                    <div class="flex flex-col gap-6 sm:flex-row sm:items-end">
                        <div class="flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-3xl bg-gradient-to-br from-cyan-500 via-indigo-500 to-violet-600 text-4xl font-black text-white shadow-2xl shadow-indigo-950/40 ring-4 ring-white/10">
                            @if($profile->avatarThumbUrl)
                                <img src="{{ $profile->avatarThumbUrl }}" alt="{{ $instructor->name }}" class="h-full w-full object-cover">
                            @else
                                {{ mb_substr($instructor->name, 0, 1) }}
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="mb-4 flex flex-wrap items-center gap-2">
                                @if($isVerified)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-400/10 px-3 py-1 text-xs font-bold uppercase tracking-wide text-emerald-200 ring-1 ring-emerald-300/20">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-300" aria-hidden="true"></span>
                                        {{ \App\Services\Instructor\InstructorTrustBadgeResolver::LABEL }}
                                    </span>
                                @endif
                                @if($primarySubject)
                                    <span class="rounded-full bg-indigo-400/10 px-3 py-1 text-xs font-semibold text-indigo-200 ring-1 ring-indigo-300/20">{{ $primarySubject['name'] }}</span>
                                @endif
                            </div>

                            <h1 class="text-4xl font-black tracking-tight text-white sm:text-5xl lg:text-6xl">{{ $instructor->name }}</h1>

                            @if($currentPosition)
                                <p class="mt-3 text-base leading-7 text-slate-300 sm:text-lg">
                                    {{ $currentPosition->designation }}@if($currentPosition->organization_name) · {{ $currentPosition->organization_name }}@endif
                                </p>
                            @elseif($headlineText)
                                <p class="mt-3 text-base leading-7 text-slate-300 sm:text-lg">{{ $headlineText }}</p>
                            @endif

                            <div class="mt-5 flex flex-wrap gap-3 text-sm text-slate-300">
                                <span class="rounded-full bg-white/[0.05] px-3 py-1.5 ring-1 ring-white/10">{{ $subjects->count() }} {{ Str::plural('subject', $subjects->count()) }}</span>
                                <span class="rounded-full bg-white/[0.05] px-3 py-1.5 ring-1 ring-white/10">{{ $stats['years_experience'] > 0 ? $stats['years_experience'].' years experience' : 'New instructor' }}</span>
                                @if($languages->isNotEmpty())
                                    <span class="rounded-full bg-white/[0.05] px-3 py-1.5 ring-1 ring-white/10">{{ $languages->take(2)->join(', ') }}</span>
                                @endif
                                @if($responseTimeLabel)
                                    <span class="rounded-full bg-white/[0.05] px-3 py-1.5 ring-1 ring-white/10">{{ $responseTimeLabel }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-3xl border border-white/10 bg-white/[0.07] p-5 shadow-2xl shadow-black/25 backdrop-blur">
                    @if($isBookable)
                        <p class="text-sm font-semibold text-white">Start with {{ Str::before($instructor->name, ' ') }}</p>
                        <p class="mt-2 text-sm leading-6 text-slate-400">
                            @if($offersDemo)
                                Choose a no-pressure demo or continue to the booking flow for a full paid session.
                            @else
                                Continue to the booking flow for a full paid session.
                            @endif
                        </p>
                        <div class="mt-5 grid gap-3">
                            @if($offersDemo)
                                <a href="{{ $demoBookingUrl }}" class="group rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 p-4 text-white shadow-lg shadow-indigo-950/30 transition hover:-translate-y-0.5 hover:shadow-indigo-950/50">
                                    <span class="text-sm font-black">Book a Free Demo</span>
                                    <span class="mt-1 block text-xs text-indigo-100">Meet the instructor and discuss your learning goal.</span>
                                </a>
                                <a href="{{ $paidBookingUrl }}" class="rounded-2xl border border-white/10 bg-slate-900/80 p-4 text-white transition hover:border-emerald-300/30 hover:bg-emerald-400/10">
                                    <span class="text-sm font-black">Book a Paid Class</span>
                                    <span class="mt-1 block text-xs text-slate-400">Move straight into a full learning session.</span>
                                </a>
                            @else
                                <a href="{{ $paidBookingUrl }}" class="group rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 p-4 text-white shadow-lg shadow-indigo-950/30 transition hover:-translate-y-0.5 hover:shadow-indigo-950/50">
                                    <span class="text-sm font-black">Book a Lesson</span>
                                    <span class="mt-1 block text-xs text-indigo-100">Move straight into a full learning session.</span>
                                </a>
                            @endif
                        </div>
                        <p class="mt-4 text-xs leading-5 text-slate-500">Final times and session type are confirmed in the booking wizard.</p>
                    @else
                        <p class="text-sm font-semibold text-white">Instructor temporarily unavailable</p>
                        <p class="mt-2 text-sm leading-6 text-slate-400">{{ Str::before($instructor->name, ' ') }} isn't accepting new lesson bookings right now. Check back later.</p>
                        <div class="mt-5 rounded-2xl border border-dashed border-white/10 p-4 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Booking disabled
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <main class="profile-content mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
        <div class="mb-9 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between" data-profile-reveal>
            <div>
                <p class="text-xs font-black uppercase tracking-[0.15em] text-indigo-600">Public teaching profile</p>
                <h2 class="mt-2 text-3xl font-black tracking-tight text-slate-950">Explore expertise, approach, and availability</h2>
                <p class="mt-3 max-w-3xl leading-7 text-slate-600">Review the information this instructor has made public before deciding whether their experience and teaching approach fit your learning goal.</p>
            </div>
            @if($isVerified)
                <span class="w-fit rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-black uppercase tracking-wide text-emerald-700">Identity and profile reviewed</span>
            @endif
        </div>
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3" data-profile-sections>
            <div class="space-y-6 lg:col-span-2">
                @if($biographyText)
                    <x-ui.card class="border-white/10 bg-white/[0.04]">
                        <p class="text-xs font-bold uppercase tracking-wide text-indigo-200">About this instructor</p>
                        <h2 class="mt-2 text-xl font-bold text-white">How {{ Str::before($instructor->name, ' ') }} can help</h2>
                        <p class="mt-4 whitespace-pre-line text-sm leading-7 text-slate-300">{{ $biographyText }}</p>
                    </x-ui.card>
                @endif

                @if($profile->instructor_teaching_experience_summary || $profile->instructor_teaching_philosophy)
                    <x-ui.card class="border-white/10 bg-white/[0.04]">
                        <p class="text-xs font-bold uppercase tracking-wide text-emerald-200">Teaching style</p>
                        <h2 class="mt-2 text-xl font-bold text-white">Approach to learning</h2>
                        @if($profile->instructor_teaching_experience_summary)
                            <div class="mt-5 rounded-2xl bg-slate-900/70 p-4 ring-1 ring-white/10">
                                <h3 class="text-sm font-bold text-slate-100">Experience Summary</h3>
                                <p class="mt-2 whitespace-pre-line text-sm leading-7 text-slate-300">{{ $profile->instructor_teaching_experience_summary }}</p>
                            </div>
                        @endif
                        @if($profile->instructor_teaching_philosophy)
                            <div class="mt-4 rounded-2xl bg-slate-900/70 p-4 ring-1 ring-white/10">
                                <h3 class="text-sm font-bold text-slate-100">Teaching Philosophy</h3>
                                <p class="mt-2 whitespace-pre-line text-sm leading-7 text-slate-300">{{ $profile->instructor_teaching_philosophy }}</p>
                            </div>
                        @endif
                    </x-ui.card>
                @endif

                @if($subjects->isNotEmpty())
                    <x-ui.card class="border-white/10 bg-white/[0.04]">
                        <p class="text-xs font-bold uppercase tracking-wide text-indigo-200">Expertise</p>
                        <h2 class="mt-2 text-xl font-bold text-white">Subjects covered</h2>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            @foreach($subjects as $subject)
                                <div class="rounded-2xl border border-white/10 bg-slate-900/70 p-4">
                                    <p class="font-bold text-white">{{ $subject['name'] }}</p>
                                    @if($subject['grade_range'])
                                        <p class="mt-1 text-sm text-slate-400">{{ $subject['grade_range'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if($topics->isNotEmpty())
                            <h3 class="mt-6 text-sm font-bold uppercase tracking-wide text-slate-400">Topics taught</h3>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach($topics as $topic)
                                    <span class="rounded-full bg-white/[0.05] px-3 py-1.5 text-sm text-slate-200 ring-1 ring-white/10">{{ $topic['subject'] }} · {{ $topic['name'] }}</span>
                                @endforeach
                            </div>
                        @endif
                    </x-ui.card>
                @endif

                @if($experiences->isNotEmpty())
                    <x-ui.card class="border-white/10 bg-white/[0.04]">
                        <p class="text-xs font-bold uppercase tracking-wide text-emerald-200">Background</p>
                        <h2 class="mt-2 text-xl font-bold text-white">Experience</h2>
                        <div class="mt-5 space-y-5">
                            @foreach($experiences as $experience)
                                <div class="border-l-2 border-indigo-400/40 pl-4">
                                    <p class="font-bold text-white">{{ $experience->designation }}</p>
                                    @if($experience->organization_name)
                                        <p class="text-sm text-slate-400">{{ $experience->organization_name }}</p>
                                    @endif
                                    <p class="mt-1 text-xs text-slate-400">
                                        {{ optional($experience->start_date)->format('M Y') }}
                                        @if($experience->end_date)
                                            - {{ $experience->end_date->format('M Y') }}
                                        @elseif($experience->is_current)
                                            - Present
                                        @endif
                                    </p>
                                    @if($experience->description)
                                        <p class="mt-2 text-sm leading-6 text-slate-300">{{ $experience->description }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                @if($skills->isNotEmpty())
                    <x-ui.card class="border-white/10 bg-white/[0.04]">
                        <h2 class="text-xl font-bold text-white">Skills</h2>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach($skills as $skill)
                                <span class="rounded-full bg-indigo-500/10 px-3 py-1 text-xs font-semibold text-indigo-200 ring-1 ring-indigo-400/20">{{ $skill }}</span>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                @if($certificates->isNotEmpty())
                    <x-ui.card class="border-white/10 bg-white/[0.04]">
                        <h2 class="text-xl font-bold text-white">Certificates</h2>
                        <div class="mt-4 space-y-3">
                            @foreach($certificates as $certificate)
                                <div class="rounded-2xl border border-white/10 bg-slate-900/70 p-4">
                                    <p class="font-bold text-white">{{ $certificate->degree ?? $certificate->education_level?->label() }}</p>
                                    <p class="text-sm text-slate-400">{{ $certificate->institution_name }}</p>
                                    <p class="mt-1 text-xs text-slate-400">{{ $certificate->certificate_number }}</p>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif
            </div>

            <aside class="space-y-6">
                <x-ui.card class="border-white/10 bg-white/[0.04]">
                    <h2 class="text-lg font-bold text-white">Instructor Snapshot</h2>
                    <dl class="mt-5 space-y-4 text-sm">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-slate-400">Rating</dt>
                            <dd class="font-bold text-white">
                                @if($reviewSummary->averageRating !== null)
                                    <span aria-hidden="true">{{ number_format($reviewSummary->averageRating, 1) }} <span class="font-normal text-slate-400">({{ $reviewSummary->reviewCount }})</span></span>
                                    <span class="sr-only">Rated {{ number_format($reviewSummary->averageRating, 1) }} out of 5 from {{ $reviewSummary->reviewCount }} {{ Str::plural('review', $reviewSummary->reviewCount) }}</span>
                                @else
                                    No ratings yet
                                @endif
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-slate-400">Experience</dt>
                            <dd class="font-bold text-white">{{ $stats['years_experience'] > 0 ? $stats['years_experience'].' years' : 'New' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-slate-400">Subjects</dt>
                            <dd class="font-bold text-white">{{ $subjects->count() }}</dd>
                        </div>
                    </dl>

                    @auth
                        @if(auth()->user()->hasRole('student') && auth()->id() !== $instructor->id)
                            @php($isFavorite = auth()->user()->favoriteInstructors()->whereKey($instructor->id)->exists())
                            <form method="POST" action="{{ $isFavorite ? route('dashboard.favorite-instructors.destroy', $instructor) : route('dashboard.favorite-instructors.store', $instructor) }}" class="mt-5">
                                @csrf
                                @if($isFavorite)
                                    @method('DELETE')
                                @endif
                                <button type="submit" class="w-full rounded-xl border border-indigo-300/20 px-4 py-2.5 text-sm font-bold text-indigo-200 transition hover:bg-indigo-500/10">
                                    {{ $isFavorite ? 'Remove from Favorites' : 'Add to Favorites' }}
                                </button>
                            </form>

                            @if(app(\App\Settings\FeatureSettings::class)->waitlist_enabled)
                                @php($isWaitlisted = auth()->user()->instructorWaitlistEntries()->where('instructor_user_id', $instructor->id)->where('status', \App\Enums\WaitlistEntryStatus::Waiting->value)->exists())
                                <form method="POST" action="{{ $isWaitlisted ? route('dashboard.waitlist.destroy', $instructor) : route('dashboard.waitlist.store', $instructor) }}" class="mt-3">
                                    @csrf
                                    @if($isWaitlisted)
                                        @method('DELETE')
                                    @endif
                                    <button type="submit" class="w-full rounded-xl border border-white/15 px-4 py-2.5 text-sm font-bold text-slate-200 transition hover:bg-white/10">
                                        {{ $isWaitlisted ? 'Leave Waitlist' : 'Join Waitlist' }}
                                    </button>
                                    <p class="mt-2 text-[11px] text-slate-500">First-come, first-served — joining does not guarantee a slot when availability opens.</p>
                                </form>
                            @endif
                        @endif
                    @else
                        <form method="POST" action="{{ route('dashboard.favorite-instructors.store', $instructor) }}" class="mt-5">
                            @csrf
                            <button type="submit" class="w-full rounded-xl border border-indigo-300/20 px-4 py-2.5 text-sm font-bold text-indigo-200 transition hover:bg-indigo-500/10">
                                Sign in to Favorite
                            </button>
                        </form>
                    @endauth
                </x-ui.card>

                @if($pricingSubject)
                    <x-ui.card class="border-white/10 bg-white/[0.04]">
                        <h2 class="text-lg font-bold text-white">Lesson Pricing</h2>

                        @if($subjects->count() > 1)
                            <div class="mt-3 flex flex-wrap gap-1.5" role="tablist" aria-label="Pricing subject">
                                @foreach($subjects as $subjectOption)
                                    <a href="{{ route('instructors.show', [$instructor, 'subject' => $subjectOption['slug']]) }}"
                                       role="tab"
                                       aria-selected="{{ $subjectOption['slug'] === $pricingSubject->slug ? 'true' : 'false' }}"
                                       class="rounded-full px-3 py-1 text-xs font-bold {{ $subjectOption['slug'] === $pricingSubject->slug ? 'bg-indigo-500 text-white' : 'bg-white/10 text-slate-300 hover:bg-white/20' }}">
                                        {{ $subjectOption['name'] }}
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        @if($countryContext->country)
                            <p class="mt-3 text-xs text-slate-400">
                                Prices for <strong class="text-slate-200">{{ $countryContext->country->name }}</strong>@if($countryContext->isGuestDefault) (default){{ ' ' }}<a href="{{ route('instructors.index') }}" class="underline">change on the marketplace</a>@endif
                            </p>

                            @if($priceQuote?->state === \App\Booking\Enums\MarketplacePriceState::Available)
                                <div class="mt-4 space-y-2">
                                    @foreach($priceQuote->options as $option)
                                        <div class="flex items-center justify-between rounded-xl bg-white/5 px-4 py-3">
                                            <span class="text-sm text-slate-300">{{ $option->durationMinutes }} minutes</span>
                                            <span class="text-base font-black text-white">{{ $option->formattedAmount }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                @if($priceQuote->isFrom)
                                    <p class="mt-2 text-[11px] text-slate-500">Prices vary by session length.</p>
                                @endif
                            @elseif($priceQuote?->state === \App\Booking\Enums\MarketplacePriceState::Unavailable)
                                <p class="mt-4 text-sm text-slate-400">Price unavailable for this selection. Continue to the booking flow for current details.</p>
                            @endif
                        @else
                            <p class="mt-3 text-sm text-slate-400">
                                @auth
                                    Complete your billing country to view local pricing.
                                @else
                                    <a href="{{ route('instructors.index') }}" class="underline">Select your billing country</a> on the marketplace to view prices.
                                @endauth
                            </p>
                        @endif
                    </x-ui.card>
                @endif

                @if($languages->isNotEmpty())
                    <x-ui.card class="border-white/10 bg-white/[0.04]">
                        <h2 class="text-lg font-bold text-white">Languages</h2>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach($languages as $language)
                                <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-slate-200">{{ $language }}</span>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                <x-ui.card class="border-white/10 bg-white/[0.04]">
                    <h2 class="text-lg font-bold text-white">Availability Preview</h2>
                    @if($availabilityPreview->isNotEmpty())
                        <div class="mt-4 space-y-3 text-sm">
                            @foreach($availabilityPreview as $slot)
                                <div class="flex items-center justify-between gap-4 rounded-xl bg-slate-900/70 px-3 py-2 ring-1 ring-white/10">
                                    <span class="font-bold text-slate-200">{{ $slot['day'] }}</span>
                                    <span class="text-slate-400">{{ $slot['time'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-4 text-sm text-slate-400">No public availability preview yet.</p>
                    @endif
                </x-ui.card>

                <x-ui.card class="border-white/10 bg-white/[0.04]">
                    <h2 class="text-lg font-bold text-white">Ratings</h2>
                    @if($reviewSummary->reviewCount > 0)
                        <div class="mt-4 space-y-1.5">
                            @for($star = 5; $star >= 1; $star--)
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="w-8 shrink-0 text-slate-400">{{ $star }}★</span>
                                    <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-800">
                                        <span class="block h-full rounded-full bg-amber-400" style="width: {{ round((($reviewSummary->ratingDistribution[(string) $star] ?? 0) / $reviewSummary->reviewCount) * 100) }}%"></span>
                                    </span>
                                    <span class="w-6 shrink-0 text-right text-slate-500">{{ $reviewSummary->ratingDistribution[(string) $star] ?? 0 }}</span>
                                </div>
                            @endfor
                        </div>
                    @else
                        <p class="mt-4 text-sm text-slate-400">
                            Ratings will appear here once course reviews are available.
                        </p>
                    @endif
                </x-ui.card>
            </aside>
        </div>

        <section class="mt-14 border-t border-indigo-100 pt-10" id="reviews" data-profile-reveal>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-emerald-200">Student feedback</p>
                    <h2 class="mt-2 text-2xl font-black text-white">Reviews &amp; Ratings</h2>
                </div>
            </div>

            @if($reviewSummary->reviewCount > 0)
                <div class="mt-6 grid gap-6 lg:grid-cols-3">
                    <x-ui.card class="border-white/10 bg-white/[0.04] lg:col-span-1">
                        <div class="flex items-baseline gap-2" aria-hidden="true">
                            <span class="text-4xl font-black text-white">{{ number_format($reviewSummary->averageRating, 1) }}</span>
                            <span class="text-sm text-slate-400">/ 5</span>
                        </div>
                        <span class="sr-only">Rated {{ number_format($reviewSummary->averageRating, 1) }} out of 5</span>
                        <p class="mt-1 text-sm text-slate-400">Based on {{ $reviewSummary->reviewCount }} {{ Str::plural('review', $reviewSummary->reviewCount) }}</p>

                        <div class="mt-5 space-y-1.5">
                            @for($star = 5; $star >= 1; $star--)
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="w-8 shrink-0 text-slate-400">{{ $star }}★</span>
                                    <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-800">
                                        <span class="block h-full rounded-full bg-amber-400" style="width: {{ round((($reviewSummary->ratingDistribution[(string) $star] ?? 0) / $reviewSummary->reviewCount) * 100) }}%"></span>
                                    </span>
                                    <span class="w-6 shrink-0 text-right text-slate-500">{{ $reviewSummary->ratingDistribution[(string) $star] ?? 0 }}</span>
                                </div>
                            @endfor
                        </div>

                        @if(collect($reviewSummary->dimensionAverages)->filter()->isNotEmpty())
                            <dl class="mt-5 space-y-2 border-t border-white/10 pt-4 text-xs">
                                @foreach($reviewSummary->dimensionAverages as $key => $average)
                                    @continue($average === null)
                                    <div class="flex items-center justify-between gap-4">
                                        <dt class="text-slate-400">{{ $dimensionLabels[$key] ?? Str::headline($key) }}</dt>
                                        <dd class="font-bold text-white">{{ number_format($average, 1) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif

                        @if($reviewSummary->paidReviewCount > 0 || $reviewSummary->demoReviewCount > 0)
                            <p class="mt-5 border-t border-white/10 pt-4 text-xs text-slate-500">
                                {{ $reviewSummary->paidReviewCount }} paid · {{ $reviewSummary->demoReviewCount }} demo
                            </p>
                        @endif
                    </x-ui.card>

                    <div class="space-y-4 lg:col-span-2">
                        @foreach($reviews as $review)
                            <x-ui.card class="border-white/10 bg-white/[0.04]">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="font-bold text-white">{{ $review->reviewerLabel }}</p>
                                        <p class="mt-0.5 text-xs text-slate-400">{{ $review->submittedAt->format('M j, Y') }}</p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if($review->verifiedLesson)
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-400/10 px-2.5 py-1 text-xs font-bold text-emerald-200 ring-1 ring-emerald-300/20">
                                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-300"></span>
                                                {{ $review->isDemo() ? 'Verified Demo Lesson' : 'Verified Lesson' }}
                                            </span>
                                        @endif
                                        <span class="rounded-full bg-amber-400/10 px-2.5 py-1 text-xs font-bold text-amber-200 ring-1 ring-amber-300/20">
                                            <span aria-hidden="true">{{ $review->overallRating }} ★</span>
                                            <span class="sr-only">Rated {{ $review->overallRating }} out of 5</span>
                                        </span>
                                    </div>
                                </div>

                                @if($review->content)
                                    <p class="mt-3 text-sm leading-6 text-slate-300">{{ $review->content }}</p>
                                @endif

                                @if(!empty($review->tags))
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @foreach($review->tags as $tag)
                                            <span class="rounded-full bg-white/[0.05] px-2.5 py-1 text-xs text-slate-300 ring-1 ring-white/10">{{ $tag['label'] ?? $tag['key'] ?? '' }}</span>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="mt-3 border-t border-white/5 pt-3">
                                    @auth
                                        <livewire:reviews.report-review :review-id="$review->id" :key="'report-review-'.$review->id" />
                                    @else
                                        <a href="{{ route('auth.login') }}" class="text-xs font-medium text-slate-500 underline decoration-dotted transition hover:text-slate-300">
                                            Sign in to report
                                        </a>
                                    @endauth
                                </div>
                            </x-ui.card>
                        @endforeach

                        <x-ui.pagination :paginator="$reviews" />
                    </div>
                </div>
            @else
                <x-ui.card class="mt-6 border-white/10 bg-white/[0.04] text-center">
                    <p class="text-sm text-slate-400">No reviews yet — {{ Str::before($instructor->name, ' ') }} hasn't received a published review.</p>
                </x-ui.card>
            @endif
        </section>

        @if($related->isNotEmpty())
            <section class="mt-14 border-t border-indigo-100 pt-10" data-profile-reveal>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-indigo-200">Keep exploring</p>
                        <h2 class="mt-2 text-2xl font-black text-white">Related Instructors</h2>
                        <p class="mt-2 text-sm text-slate-400">Compare similar public profiles before you choose a session.</p>
                    </div>
                    <a href="{{ route('instructors.index') }}" class="text-sm font-bold text-indigo-200 transition hover:text-white">View all instructors</a>
                </div>

                <div class="mt-6 flex gap-5 overflow-x-auto pb-3 snap-x snap-mandatory xl:grid xl:grid-cols-3 xl:overflow-visible xl:pb-0">
                    @foreach($related as $relatedInstructor)
                        <div class="w-[20rem] shrink-0 snap-start sm:w-[24rem] xl:w-auto">
                            <x-instructor.card :instructor="$relatedInstructor" />
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </main>

</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const page = document.querySelector('[data-instructor-profile]');
    if (! page || window.matchMedia('(prefers-reduced-motion: reduce)').matches || ! ('IntersectionObserver' in window)) return;

    const targets = [
        ...page.querySelectorAll('[data-profile-reveal]'),
        ...page.querySelectorAll('[data-profile-sections] > div > *'),
        ...page.querySelectorAll('[data-profile-sections] > aside > *'),
    ];

    page.classList.add('profile-motion-ready');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (! entry.isIntersecting) return;
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -7% 0px', threshold: 0.1 });

    targets.forEach((element, index) => {
        element.dataset.profileMotion = '';
        element.style.setProperty('--profile-delay', `${Math.min((index % 3) * 80, 160)}ms`);
        observer.observe(element);
    });
});
</script>
@endpush
@endsection
