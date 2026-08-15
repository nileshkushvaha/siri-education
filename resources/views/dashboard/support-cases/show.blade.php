@extends('layouts.account')

@section('title', $case->case_number . ' — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Support Cases', 'url' => route('dashboard.support-cases')],
        ['label' => $case->case_number],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-white">{{ $case->subject }}</h1>
            <p class="text-slate-400 text-sm mt-1">
                {{ $case->case_number }} &middot; {{ $case->category->label() }} &middot; Opened {{ viewer_date($case->opened_at) }}
            </p>
        </div>
        <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold bg-white/[0.06] text-white flex-shrink-0">
            {{ $case->status->label() }}
        </span>
    </div>

    <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-5 sm:p-7 mb-5">
        <h2 class="text-sm font-semibold text-white mb-2">Description</h2>
        <p class="text-sm text-slate-300 whitespace-pre-line">{{ $case->description }}</p>
    </div>

    @if($case->resolution_summary)
        <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/[0.04] p-5 sm:p-7 mb-5">
            <h2 class="text-sm font-semibold text-emerald-300 mb-2">Resolution</h2>
            <p class="text-sm text-slate-300 whitespace-pre-line">{{ $case->resolution_summary }}</p>
        </div>
    @endif

    <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-5 sm:p-7 mb-5">
        <h2 class="text-sm font-semibold text-white mb-4">Replies</h2>

        <div class="space-y-4">
            @forelse($case->requesterVisibleReplies as $reply)
                <div wire:key="reply-{{ $reply->id }}" class="{{ !$loop->last ? 'pb-4 border-b border-white/[0.05]' : '' }}">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-xs font-semibold text-white">{{ $reply->author->name }}</p>
                        <p class="text-xs text-slate-500">{{ viewer_datetime($reply->created_at) }}</p>
                    </div>
                    <p class="text-sm text-slate-300 whitespace-pre-line">{{ $reply->body }}</p>
                </div>
            @empty
                <p class="text-sm text-slate-400">No replies yet.</p>
            @endforelse
        </div>

        @can('reply', $case)
            <form method="POST" action="{{ route('dashboard.support-cases.reply', $case) }}" class="mt-6 pt-5 border-t border-white/[0.05]">
                @csrf
                <label for="body" class="block text-xs font-semibold text-slate-400 mb-2">Add a reply</label>
                <textarea id="body" name="body" rows="3" maxlength="4000" required
                    class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border @error('body') border-red-500/50 @else border-white/[0.05] @enderror text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                    placeholder="Write your reply">{{ old('body') }}</textarea>
                @error('body')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                <button type="submit"
                    class="mt-3 inline-flex min-h-11 items-center px-5 py-2.5 rounded-lg bg-indigo-500 text-sm font-semibold text-white hover:bg-indigo-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
                    Send Reply
                </button>
            </form>
        @endcan
    </div>

    @can('reopen', $case)
        <form method="POST" action="{{ route('dashboard.support-cases.reopen', $case) }}">
            @csrf
            <button type="submit"
                class="inline-flex min-h-11 items-center px-4 py-2 rounded-lg bg-white/[0.06] text-sm font-semibold text-white hover:bg-white/[0.1] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
                Reopen Case
            </button>
        </form>
    @endcan

@endsection
