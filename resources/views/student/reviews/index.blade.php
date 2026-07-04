@extends('layouts.account')

@section('title', 'My Reviews — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Reviews'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Reviews</h1>
        <p class="text-slate-400 text-sm mt-1">Course reviews you've submitted.</p>
    </div>

    <x-account.card title="My Reviews">
        <x-student.coming-soon
            icon="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"
            title="No Reviews Yet"
            message="Complete a course and share your experience. Your reviews help other students make better decisions." />
    </x-account.card>

@endsection
