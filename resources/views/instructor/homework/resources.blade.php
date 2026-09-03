@extends('layouts.account')

@section('title', 'Resource Library — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Homework', 'url' => route('dashboard.instructor.homework')],
        ['label' => 'Resource Library'],
    ]" />
@endsection

@section('account-content')
    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">Resource Library</h1>
        <p class="text-fg-muted text-sm mt-1">Maintain reusable teaching resources and attach them to homework across your students.</p>
    </div>

    <livewire:frontend.instructor.homework-resource-library />
@endsection
