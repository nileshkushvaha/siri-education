@extends('layouts.account')

@section('title', 'My Lessons — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'My Lessons'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-white">My Lessons</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">
            Review your lessons and record private feedback after a lesson completes. Feedback is never shared publicly.
        </p>
    </div>

    <livewire:frontend.instructor.lesson-feedback-manager />
@endsection
