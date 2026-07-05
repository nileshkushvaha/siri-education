@extends('layouts.error')

@section('title', '419 Page Expired')
@section('meta_description', 'Your session expired.')
@section('code', '419')
@section('error-title', 'Page expired')
@section('error-message', 'Your session or form expired. Refresh the page and try again.')

@section('error-actions')
    <button type="button" onclick="window.location.reload()" class="inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
        Refresh page
    </button>
    <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-medium text-slate-200 transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
        Go Home
    </a>
@endsection
