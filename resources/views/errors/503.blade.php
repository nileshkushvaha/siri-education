@extends('layouts.error')

@section('title', '503 Service Unavailable')
@section('meta_description', 'The service is temporarily unavailable.')
@section('code', '503')
@section('error-title', 'Service unavailable')
@section('error-message', 'The site is temporarily unavailable. Please check back soon.')

@section('error-actions')
    <button type="button" onclick="window.location.reload()" class="inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
        Try again
    </button>
    <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-medium text-slate-200 transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
        Go Home
    </a>
@endsection
