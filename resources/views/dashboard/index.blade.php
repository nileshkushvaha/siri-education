@extends('layouts.account')

@section('title', 'Dashboard — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[['label' => 'Dashboard']]" />
@endsection

@section('account-content')
    <x-account.page-header
        :date="now()->format('l, F j, Y')"
        :name="auth()->user()->first_name ?? explode(' ', auth()->user()->name)[0]"
    />

    @if($portalAudience === \App\Enums\PortalAudience::Student)
        <livewire:frontend.student.dashboard-overview />
    @elseif($portalAudience === \App\Enums\PortalAudience::Instructor)
        <livewire:frontend.instructor.dashboard-overview />
    @endif
@endsection
