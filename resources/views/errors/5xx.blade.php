@extends('layouts.error')

@section('title', 'Server Error')
@section('meta_description', 'Something went wrong on our end.')
@section('code', method_exists($exception, 'getStatusCode') ? (string) $exception->getStatusCode() : '5xx')
@section('error-title', 'Something went wrong')
@section('error-message', 'We encountered an unexpected error. Please try again in a moment.')

@section('error-actions')
    <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
        Go Home
    </a>
    <button type="button" onclick="window.location.reload()" class="inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-medium text-slate-200 transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
        Try again
    </button>
@endsection
