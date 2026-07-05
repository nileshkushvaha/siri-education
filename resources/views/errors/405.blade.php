@extends('layouts.error')

@section('title', '405 Method Not Allowed')
@section('meta_description', 'This page does not support the requested method.')
@section('code', '405')
@section('error-title', 'Method not allowed')
@section('error-message', 'This link cannot be opened directly. Please use the page action that brought you here.')

@section('error-actions')
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
        Go back
    </a>
    <a href="{{ url('/') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-medium text-slate-200 transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
        Go Home
    </a>
@endsection
