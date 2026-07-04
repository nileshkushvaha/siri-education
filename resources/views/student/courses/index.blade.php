@extends('layouts.account')

@section('title', 'My Courses — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'My Courses'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">My Courses</h1>
        <p class="text-slate-400 text-sm mt-1">Your enrolled, purchased, and assigned courses.</p>
    </div>

    <x-account.card title="My Courses">
        <x-student.coming-soon
            icon="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"
            title="Courses Coming Soon"
            message="Enroll in courses to start your learning journey. This feature will be available when the Course module ships." />
    </x-account.card>

@endsection
