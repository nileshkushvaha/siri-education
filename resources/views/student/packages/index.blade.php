@extends('layouts.account')

@section('title', 'Lesson Packages — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Lesson Packages'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">Lesson Packages</h1>
        <p class="text-fg-muted text-sm mt-1">Review instructor-approved packages, complete payment, and track your remaining lessons.</p>
    </div>

    <livewire:frontend.student.package-proposals />
@endsection
