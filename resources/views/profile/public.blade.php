@extends('layouts.frontend')

@section('title', $summary->name . ' — ' . config('app.name'))

@section('breadcrumbs')
    <x-frontend.breadcrumb :crumbs="[['label' => $summary->name]]" />
@endsection

@section('content')
<div class="min-h-screen bg-surface-dark">
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

        <x-account.profile-header :summary="$summary" variant="full" />

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                @if($profile->bio)
                    <x-account.card title="About">
                        <p class="text-slate-300 text-sm leading-relaxed">{{ $profile->bio }}</p>
                    </x-account.card>
                @endif

                <x-account.experience-timeline :experiences="$experiences" />
                <x-account.education-timeline :educations="$educations" />
            </div>

            <div class="space-y-6">
                <x-account.card title="Overview">
                    <div class="space-y-4">
                        @if($currentPosition)
                            <div>
                                <p class="text-slate-400 text-xs mb-1">Current Position</p>
                                <p class="text-white text-sm font-medium">{{ $currentPosition->designation }}</p>
                                <p class="text-slate-400 text-xs">{{ $currentPosition->organization_name }}</p>
                            </div>
                        @endif

                        @if($yearsOfExperience > 0)
                            <div>
                                <p class="text-slate-400 text-xs mb-1">Years of Experience</p>
                                <p class="text-white text-sm font-medium">{{ $yearsOfExperience }} years</p>
                            </div>
                        @endif

                        @if($latestEducation)
                            <div>
                                <p class="text-slate-400 text-xs mb-1">Education</p>
                                <p class="text-white text-sm font-medium">{{ $latestEducation->degree ?? $latestEducation->education_level?->label() }}</p>
                                <p class="text-slate-400 text-xs">{{ $latestEducation->institution_name }}</p>
                            </div>
                        @endif

                        @if(! $currentPosition && $yearsOfExperience <= 0 && ! $latestEducation)
                            <p class="text-slate-400 text-sm">No details added yet.</p>
                        @endif
                    </div>
                </x-account.card>

                @if($profile->show_social_links && ($profile->website || $profile->linkedin || $profile->github || $profile->twitter || $profile->facebook || $profile->instagram || $profile->youtube))
                    <x-account.card title="Links">
                        <div class="space-y-2">
                            @foreach(['website' => 'Website', 'linkedin' => 'LinkedIn', 'github' => 'GitHub', 'twitter' => 'Twitter', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube'] as $field => $label)
                                @if($profile->{$field})
                                    <a href="{{ $profile->{$field} }}" target="_blank" rel="noopener noreferrer" class="block text-sm text-indigo-400 hover:text-indigo-300 transition">{{ $label }}</a>
                                @endif
                            @endforeach
                        </div>
                    </x-account.card>
                @endif
            </div>
        </div>
    </main>
</div>
@endsection
