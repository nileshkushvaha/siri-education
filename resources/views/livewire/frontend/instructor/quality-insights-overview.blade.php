<div class="space-y-6">
    {{-- Overall rating summary --}}
    @if($insights->ratingSummary->reviewCount > 0)
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-account.stat-card
                label="Average Rating"
                :value="number_format($insights->ratingSummary->averageRating, 1)"
                gradient="from-amber-500 to-orange-500"
                icon="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"
            />
            <x-account.stat-card
                label="Eligible Reviews"
                :value="(string) $insights->ratingSummary->reviewCount"
                gradient="from-indigo-500 to-violet-500"
                icon="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
            />
            <x-account.stat-card
                label="Paid Lesson Reviews"
                :value="(string) $insights->ratingSummary->paidReviewCount"
                gradient="from-emerald-500 to-teal-500"
                icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V6m0 10v2m9-6a9 9 0 11-18 0 9 9 0 0118 0z"
            />
            <x-account.stat-card
                label="Demo Lesson Reviews"
                :value="(string) $insights->ratingSummary->demoReviewCount"
                gradient="from-sky-500 to-blue-500"
                icon="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"
            />
        </div>
    @else
        <x-account.card>
            <p class="text-sm text-fg-muted">
                You do not have any published reviews yet. Once students leave public reviews after completed lessons, your rating summary will appear here.
            </p>
        </x-account.card>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Rating distribution --}}
        <x-account.card title="Rating Distribution">
            @if($insights->ratingSummary->reviewCount > 0)
                <div class="space-y-2">
                    @for($star = 5; $star >= 1; $star--)
                        @php($starCount = $insights->ratingSummary->ratingDistribution[(string) $star] ?? 0)
                        <div class="flex items-center gap-3 text-xs">
                            <span class="w-8 shrink-0 text-fg-muted">{{ $star }}★</span>
                            <span class="h-2 flex-1 overflow-hidden rounded-full bg-surface-raised">
                                <span class="block h-full rounded-full bg-amber-400" style="width: {{ round(($starCount / $insights->ratingSummary->reviewCount) * 100) }}%"></span>
                            </span>
                            <span class="w-8 shrink-0 text-right text-fg-muted">{{ $starCount }}</span>
                        </div>
                    @endfor
                </div>
            @else
                <p class="text-sm text-fg-muted">No ratings yet.</p>
            @endif
        </x-account.card>

        {{-- Dimension averages --}}
        <x-account.card title="Dimension Averages">
            @php($dimensionLabels = \App\Reviews\DTOs\InstructorRatingSummaryData::dimensionLabels())
            <dl class="space-y-3">
                @foreach($dimensionLabels as $key => $label)
                    @php($average = $insights->ratingSummary->dimensionAverages[$key] ?? null)
                    @php($count = $insights->ratingSummary->dimensionCounts[$key] ?? 0)
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-fg-muted">{{ $label }}</dt>
                        <dd class="font-semibold text-fg-strong">
                            @if($average !== null)
                                {{ number_format($average, 1) }} <span class="text-xs font-normal text-fg-faint">({{ $count }})</span>
                            @else
                                <span class="text-xs font-normal text-fg-faint">Limited data available</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-account.card>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Review highlights --}}
        <x-account.card title="Review Highlights">
            @if(count($insights->topDimensions) > 0)
                <ul class="space-y-3">
                    @foreach($insights->topDimensions as $dimension)
                        <li class="flex items-center justify-between gap-4 rounded-xl bg-surface-raised px-4 py-3 text-sm">
                            <span class="text-fg">{{ $dimension->label }}</span>
                            <span class="font-semibold text-emerald-600 dark:text-emerald-300">{{ number_format($dimension->average, 1) }} <span class="text-xs font-normal text-fg-faint">({{ $dimension->reviewCount }} reviews)</span></span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-fg-muted">Limited data available — highlights appear once enough published reviews rate the same area highly.</p>
            @endif
        </x-account.card>

        {{-- Areas for improvement --}}
        <x-account.card title="Areas for Improvement">
            @if(count($insights->improvementAreas) > 0)
                <ul class="space-y-3">
                    @foreach($insights->improvementAreas as $dimension)
                        <li class="rounded-xl bg-surface-raised px-4 py-3 text-sm">
                            <div class="flex items-center justify-between gap-4">
                                <span class="text-fg">{{ $dimension->label }}</span>
                                <span class="font-semibold text-amber-600 dark:text-amber-300">{{ number_format($dimension->average, 1) }}</span>
                            </div>
                            <p class="mt-1 text-xs text-fg-faint">Students rated this area lower across {{ $dimension->reviewCount }} {{ Str::plural('review', $dimension->reviewCount) }}.</p>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-fg-muted">Limited data available — no lower-rated area stands out yet.</p>
            @endif
        </x-account.card>
    </div>

    {{-- Student feedback tags --}}
    <x-account.card title="Student Feedback Tags">
        @if(count($insights->feedbackTags) > 0)
            <div class="flex flex-wrap gap-2">
                @foreach($insights->feedbackTags as $tag)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-raised px-3 py-1.5 text-xs text-fg ring-1 ring-edge">
                        {{ $tag->label }}
                        <span class="text-fg-faint">{{ $tag->count }}</span>
                    </span>
                @endforeach
            </div>
        @else
            <p class="text-sm text-fg-muted">No feedback tags selected on your published reviews yet.</p>
        @endif
    </x-account.card>

    {{-- Recent published reviews --}}
    <x-account.card title="Recent Published Reviews">
        @if($reviews->isNotEmpty())
            <div class="space-y-4">
                @foreach($reviews as $review)
                    <div class="rounded-xl border border-edge bg-surface-raised p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-fg-strong">{{ $review->reviewerLabel }}</p>
                                <p class="mt-0.5 text-xs text-fg-faint">{{ viewer_date($review->submittedAt) }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if($review->verifiedLesson)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-400/10 px-2.5 py-1 text-xs font-semibold text-emerald-600 dark:text-emerald-300 ring-1 ring-emerald-400/20">
                                        {{ $review->isDemo() ? 'Verified Demo Lesson' : 'Verified Lesson' }}
                                    </span>
                                @endif
                                <span class="rounded-full bg-amber-400/10 px-2.5 py-1 text-xs font-semibold text-amber-600 dark:text-amber-300 ring-1 ring-amber-400/20">{{ $review->overallRating }} ★</span>
                            </div>
                        </div>

                        @if($review->content)
                            <p class="mt-3 text-sm leading-6 text-fg-muted">{{ $review->content }}</p>
                        @endif

                        @if(!empty($review->tags))
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach($review->tags as $tag)
                                    <span class="rounded-full bg-surface-raised px-2.5 py-1 text-xs text-fg-muted ring-1 ring-edge">{{ $tag['label'] ?? $tag['key'] ?? '' }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-6">
                <x-ui.pagination :paginator="$reviews" />
            </div>
        @else
            <p class="text-sm text-fg-muted">No published reviews yet.</p>
        @endif
    </x-account.card>
</div>
