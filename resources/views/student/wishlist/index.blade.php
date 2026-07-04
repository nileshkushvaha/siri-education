@extends('layouts.account')

@section('title', 'My Wishlist — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Wishlist'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6">
        <h1 class="text-xl font-bold text-white">Wishlist</h1>
        <p class="text-slate-400 text-sm mt-1">Courses you've saved for later.</p>
    </div>

    <x-account.card title="My Wishlist">
        <x-student.coming-soon
            icon="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"
            title="Wishlist Coming Soon"
            message="Save courses you want to take later. Your wishlist will be available when the Course module ships." />
    </x-account.card>

@endsection
