@extends('layouts.account')

@section('title', 'Instructor account — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Learning features'],
    ]" />
@endsection

@section('account-content')
    {{-- Shown by EnsureStudentWorkspaceAccess to an account that holds the
         instructor role only. The student role is granted at registration
         and never afterwards, so the only way forward is a separate account. --}}
    <x-account.card data-student-access="unavailable">
        <div class="max-w-2xl">
            <p class="text-xs font-semibold uppercase tracking-[.18em] text-indigo-600 dark:text-indigo-300">Instructor account</p>
            <h1 class="mt-2 text-2xl font-bold text-fg-strong">This is an instructor account</h1>
            <p class="mt-3 text-sm leading-6 text-fg-muted">
                Booking lessons, homework, the wallet and payments are only available on a student account.
                Accounts registered to teach cannot be changed into student accounts, and an instructor account
                cannot book lessons with other instructors.
            </p>
            <div class="mt-5 rounded-2xl border border-edge bg-surface-raised p-4">
                <p class="text-sm font-semibold text-fg-strong">Want to learn on {{ config('app.name') }}?</p>
                <p class="mt-1 text-sm text-fg-muted">Register a separate student account using a different email address. You can stay signed in here as an instructor, and use that account whenever you want to book lessons.</p>
            </div>
            <div class="mt-6 flex flex-wrap gap-3">
                <x-ui.button :href="route('dashboard')" size="md">Back to my instructor dashboard</x-ui.button>
                <x-ui.button :href="route('dashboard.support-cases.create')" variant="secondary" size="md">Contact support</x-ui.button>
            </div>
        </div>
    </x-account.card>
@endsection
