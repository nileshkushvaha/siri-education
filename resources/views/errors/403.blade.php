@extends('layouts.error')

@section('title', '403 Forbidden')
@section('meta_description', 'You do not have permission to access this page.')
@section('code', '403')
@section('error-title', 'Access denied')
@section('error-message', $exception->getMessage() ?: 'You do not have permission to view this page.')

@section('error-actions')
    <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
        Go Home
    </a>
    @auth
        <a href="{{ url()->previous() }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-medium text-slate-200 transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
            Go back
        </a>
    @elseif(Route::has('auth.login'))
        <a href="{{ route('auth.login') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-medium text-slate-200 transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
            Sign in
        </a>
    @endauth
@endsection
