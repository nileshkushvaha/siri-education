@extends('layouts.account')

@section('title', 'Dashboard — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[['label' => 'Dashboard']]" />
@endsection

@section('account-content')
    <x-account.page-header
        :name="auth()->user()->first_name ?? explode(' ', auth()->user()->name)[0]"
    />

    @if($portalAudience === \App\Enums\PortalAudience::Student)
        @if(! empty($profileMissing))
        <div class="mb-6 flex flex-col gap-4 rounded-2xl border border-amber-500/30 bg-amber-500/10 p-5 sm:flex-row sm:items-center sm:justify-between" role="status">
            <div>
                <p class="text-sm font-semibold text-amber-700 dark:text-amber-300">Complete your profile to start booking</p>
                <p class="mt-1 text-xs text-amber-700/80 dark:text-amber-300/80">
                    We still need your
                    {{ collect($profileMissing)->map(fn (string $k) => ['name' => 'name', 'country' => 'country', 'phone' => 'mobile number', 'terms' => 'agreement to the terms'][$k] ?? $k)->join(', ', ' and ') }}.
                    It takes under a minute.
                </p>
            </div>
            <a href="{{ route('account.complete-profile') }}" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl bg-amber-500 px-5 text-sm font-semibold text-slate-950 transition hover:bg-amber-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-300">Complete profile</a>
        </div>
        @endif
        <livewire:frontend.student.dashboard-overview :unread-count="$accountNotificationCount ?? 0" />
    @elseif($portalAudience === \App\Enums\PortalAudience::Instructor)
        <livewire:frontend.instructor.dashboard-overview :unread-count="$accountNotificationCount ?? 0" :pending-homework-count="$accountHomeworkPendingCount ?? 0" />
    @endif
@endsection
