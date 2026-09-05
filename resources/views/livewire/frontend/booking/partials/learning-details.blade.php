@php
    $levelSectionOpen = $currentPhase === 'level';
    $subjectSectionOpen = $currentPhase === 'academic_subject';
    $curriculumSectionOpen = $currentPhase === 'curriculum';
    $selectedSubject = collect($academicSubjects)->firstWhere('id', $academicSubjectId);
    $legacySubjectLabel = $subject ? ucfirst(str_replace(['_', '-'], ' ', $subject)) : null;
@endphp

<div class="space-y-6">
    <div>
        <h2 data-booking-step-title tabindex="-1" class="text-2xl font-black tracking-tight text-fg-strong outline-none">Learning details</h2>
        <p class="mt-1.5 text-sm leading-6 text-fg-muted">
            @if($prefilledLearning)
                We have used the details from your last booking. Change anything that is different this time.
            @else
                Tell us what you need help with. Only a few quick choices.
            @endif
        </p>
    </div>

    {{-- Session type --}}
    @if($type === null || $currentPhase === 'mode')
        <section aria-labelledby="booking-session-type">
            <h3 id="booking-session-type" class="text-sm font-black uppercase tracking-wide text-fg-muted">Session type</h3>
            @if(empty($types))
                <x-ui.empty-state title="No session types available" description="Please check back soon." class="mt-3" />
            @else
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach($types as $typeOption)
                        <x-booking.option-card
                            wire:click="selectMode({{ \Illuminate\Support\Js::from($typeOption['key']) }})"
                            :selected="$type === $typeOption['key']"
                            :title="$typeOption['name']"
                            :description="$typeOption['description'] ?: ($typeOption['is_paid'] ? 'Continue learning with a paid lesson.' : 'Try an instructor once, free of charge.')"
                            :badge="$typeOption['is_paid'] ? 'Paid' : 'Free'"
                        />
                    @endforeach
                </div>
            @endif
        </section>
    @elseif($selectedType)
        <x-booking.chosen-row label="Session type" :value="$selectedType['name']" phase="mode" />
    @endif

    @if($type !== null && $academicFlowActive)
        {{-- Level --}}
        @if($levelSectionOpen)
            <section aria-labelledby="booking-level">
                <h3 id="booking-level" class="text-lg font-black text-fg-strong">Choose a {{ $levelTermSingular }}</h3>
                <p class="mt-1 text-sm text-fg-muted">Pick the {{ \Illuminate\Support\Str::lower($levelTermSingular) }} the student is in.</p>
                @if(empty($levels))
                    <x-ui.empty-state
                        title="Not available yet"
                        :description="$academicFlowUnavailable ? 'Booking is not configured for your country yet. Please check back soon.' : 'No '.\Illuminate\Support\Str::lower($levelTermPlural).' are configured for this education system yet.'"
                        class="mt-4"
                    />
                @else
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach($levels as $levelOption)
                            <x-booking.option-card
                                wire:click="selectLevel({{ \Illuminate\Support\Js::from($levelOption['id']) }})"
                                :selected="$educationSystemLevelId === $levelOption['id']"
                                :title="$levelOption['display_label']"
                                align="center"
                                size="sm"
                                class="min-h-14"
                            />
                        @endforeach
                    </div>
                @endif
            </section>
        @elseif($selectedLevel)
            <x-booking.chosen-row :label="$levelTermSingular" :value="$selectedLevel['display_label']" phase="level" />
        @endif

        {{-- Subject --}}
        @if($subjectSectionOpen)
            <section aria-labelledby="booking-subject">
                <h3 id="booking-subject" class="text-lg font-black text-fg-strong">Choose a subject</h3>
                <p class="mt-1 text-sm text-fg-muted">What would you like help with?</p>
                @if(empty($academicSubjects))
                    <x-ui.empty-state title="No subjects available" description="Please choose a different {{ \Illuminate\Support\Str::lower($levelTermSingular) }}, or check back soon." class="mt-4" />
                @else
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach($academicSubjects as $subjectOption)
                            <x-booking.option-card
                                wire:click="selectAcademicSubject({{ \Illuminate\Support\Js::from($subjectOption['id']) }})"
                                :selected="$academicSubjectId === $subjectOption['id']"
                                :title="$subjectOption['name']"
                                size="sm"
                                class="min-h-14"
                            />
                        @endforeach
                    </div>
                @endif
            </section>
        @elseif($selectedSubject && $educationSystemLevelId)
            <x-booking.chosen-row label="Subject" :value="$selectedSubject['name']" phase="academic_subject" />
        @endif

        {{-- Curriculum --}}
        @if($curriculumSectionOpen)
            <section aria-labelledby="booking-curriculum">
                <h3 id="booking-curriculum" class="text-lg font-black text-fg-strong">Choose a curriculum</h3>
                <p class="mt-1 text-sm text-fg-muted">The curriculum decides which instructors can teach this session.</p>
                @if(empty($curricula))
                    <x-ui.empty-state title="No curricula available" description="No published curriculum (or eligible instructor) is available for this selection yet. Please choose a different subject, or check back soon." class="mt-4" />
                @else
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach($curricula as $curriculumOption)
                            <x-booking.option-card
                                wire:click="selectCurriculum({{ \Illuminate\Support\Js::from($curriculumOption['id']) }})"
                                :selected="$curriculumId === $curriculumOption['id']"
                                :title="$curriculumOption['name']"
                            />
                        @endforeach
                    </div>
                @endif
            </section>
        @elseif($selectedCurriculum && $academicSubjectId)
            <x-booking.chosen-row label="Curriculum" :value="$selectedCurriculum['name']" phase="curriculum" />
        @endif
    @endif

    @if($type !== null && ! $academicFlowActive && ! $academicFlowBlocked)
        {{-- Legacy free-text subject/grade chain, reachable only when the country-aware chain is not active. --}}
        @if($currentPhase === 'subject')
            <section aria-labelledby="booking-legacy-subject">
                <h3 id="booking-legacy-subject" class="text-lg font-black text-fg-strong">Choose a subject</h3>
                @if(empty($subjects))
                    <x-ui.empty-state title="No subjects available" description="Please check back soon." class="mt-4" />
                @else
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach($subjects as $subjectOption)
                            <x-booking.option-card
                                wire:click="selectSubject({{ \Illuminate\Support\Js::from($subjectOption) }})"
                                :selected="$subject === $subjectOption"
                                :title="ucfirst(str_replace(['_', '-'], ' ', $subjectOption))"
                                size="sm"
                                class="min-h-14"
                            />
                        @endforeach
                    </div>
                @endif
            </section>
        @elseif($legacySubjectLabel)
            <x-booking.chosen-row label="Subject" :value="$legacySubjectLabel" phase="subject" />
        @endif

        @if($currentPhase === 'grade')
            <section aria-labelledby="booking-legacy-grade">
                <h3 id="booking-legacy-grade" class="text-lg font-black text-fg-strong">Choose a grade</h3>
                <div class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6">
                    @foreach($grades as $gradeOption)
                        <x-booking.option-card
                            wire:click="selectGrade({{ $gradeOption }})"
                            :selected="$grade === $gradeOption"
                            :title="'Grade '.$gradeOption"
                            align="center"
                            size="sm"
                            class="min-h-14"
                        />
                    @endforeach
                </div>
            </section>
        @elseif($grade !== null && $subject !== null)
            <x-booking.chosen-row label="Grade" :value="'Grade '.$grade" phase="grade" />
        @endif
    @endif
</div>
