@extends('layouts.error')

@section('title', '401 Unauthorized')
@section('meta_description', 'Please sign in to continue.')
@section('code', '401')
@section('error-title', 'Sign in required')
@section('error-message', 'Please sign in before continuing to this page.')

@section('error-actions')
    @if(Route::has('auth.login'))
        <a href="{{ route('auth.login') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
            Sign in
        </a>
    @endif
    <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-medium text-slate-200 transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
        Go Home
    </a>
@endsection
