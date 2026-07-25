@extends('layouts.account')

@php($other = $conversation->student_id === auth()->id() ? $conversation->instructor : $conversation->student)

@section('title', $other?->name . ' — Messages — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Messages', 'url' => route('dashboard.messages')],
        ['label' => $other?->name ?? 'Conversation'],
    ]" />
@endsection

@section('account-content')

    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-white">{{ $other?->name }}</h1>
            <p class="text-slate-400 text-sm mt-1">
                Context:
                @if($conversation->context_type === \App\Models\Booking::class)
                    <a href="{{ ($accountAudience ?? null) === \App\Enums\PortalAudience::Instructor ? route('dashboard.instructor.lessons') : route('dashboard.my-bookings') }}" class="underline hover:text-white">Related booking</a>
                @else
                    <a href="{{ ($accountAudience ?? null) === \App\Enums\PortalAudience::Instructor ? route('dashboard.instructor.learning-plans') : route('dashboard.learning-plans') }}" class="underline hover:text-white">Related learning plan</a>
                @endif
            </p>
        </div>
        <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold bg-white/[0.06] text-white flex-shrink-0">
            {{ $conversation->status->label() }}
        </span>
    </div>

    <div class="rounded-2xl border border-white/[0.04] bg-white/[0.025] backdrop-blur-xl p-5 sm:p-7 mb-5">
        <div class="space-y-4">
            @forelse($conversation->messages as $msg)
                @php($isMine = $msg->sender_id === auth()->id())
                <div wire:key="message-{{ $msg->id }}" class="{{ !$loop->last ? 'pb-4 border-b border-white/[0.05]' : '' }}">
                    <div class="flex items-center gap-2 mb-1">
                        <p class="text-xs font-semibold {{ $isMine ? 'text-indigo-300' : 'text-white' }}">{{ $isMine ? 'You' : $msg->sender->name }}</p>
                        <p class="text-xs text-slate-500">{{ $msg->sent_at->format('M j, Y H:i') }}</p>
                    </div>
                    <p class="text-sm text-slate-300 whitespace-pre-line">{{ $msg->body }}</p>

                    @if($msg->getFirstMedia('attachment'))
                        <a href="{{ $msg->getFirstMediaUrl('attachment') }}" class="mt-1 inline-block text-xs text-indigo-300 underline" target="_blank" rel="noopener">Attachment</a>
                    @endif

                    @if(!$isMine)
                        <details class="mt-2">
                            <summary class="text-xs text-slate-500 cursor-pointer hover:text-slate-300">Report this message</summary>
                            <form method="POST" action="{{ route('dashboard.messages.report', [$conversation, $msg]) }}" class="mt-2 flex flex-col gap-2 max-w-sm">
                                @csrf
                                <select name="reason" required class="min-h-11 px-3 py-2 rounded-lg bg-white/[0.05] border border-white/[0.05] text-slate-200 text-xs">
                                    @foreach(\App\Messaging\Enums\MessageReportReason::cases() as $reason)
                                        <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                                    @endforeach
                                </select>
                                <textarea name="details" rows="2" maxlength="1000" placeholder="Optional details" class="px-3 py-2 rounded-lg bg-white/[0.05] border border-white/[0.05] text-slate-200 text-xs"></textarea>
                                <button type="submit" class="self-start inline-flex min-h-9 items-center px-3 py-1.5 rounded-lg bg-white/[0.06] text-xs font-semibold text-white hover:bg-white/[0.1]">
                                    Submit report
                                </button>
                            </form>
                        </details>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-400">No messages yet.</p>
            @endforelse
        </div>

        @can('reply', $conversation)
            <form method="POST" action="{{ route('dashboard.messages.reply', $conversation) }}" enctype="multipart/form-data" class="mt-6 pt-5 border-t border-white/[0.05]">
                @csrf
                <label for="body" class="block text-xs font-semibold text-slate-400 mb-2">Reply</label>
                <textarea id="body" name="body" rows="3" maxlength="2000" required
                    class="w-full px-4 py-3 rounded-xl bg-white/[0.05] border @error('body') border-red-500/50 @else border-white/[0.05] @enderror text-slate-200 placeholder-slate-600 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                    placeholder="Write your message">{{ old('body') }}</textarea>
                @error('body')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror

                <div class="mt-3">
                    <label for="attachment" class="block text-xs font-semibold text-slate-400 mb-2">Attachment (PDF or image, optional)</label>
                    <input type="file" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp" class="text-xs text-slate-300">
                    @error('attachment')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>

                <button type="submit"
                    class="mt-3 inline-flex min-h-11 items-center px-5 py-2.5 rounded-lg bg-indigo-500 text-sm font-semibold text-white hover:bg-indigo-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
                    Send
                </button>
            </form>
        @else
            <p class="mt-6 pt-5 border-t border-white/[0.05] text-sm text-slate-400">This conversation is {{ strtolower($conversation->status->label()) }} and cannot accept new messages.</p>
        @endcan
    </div>

    @can('close', $conversation)
        @if($conversation->status->value === 'active')
            <form method="POST" action="{{ route('dashboard.messages.close', $conversation) }}">
                @csrf
                <button type="submit"
                    class="inline-flex min-h-11 items-center px-4 py-2 rounded-lg bg-white/[0.06] text-sm font-semibold text-white hover:bg-white/[0.1] focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300">
                    Close Conversation
                </button>
            </form>
        @endif
    @endcan

@endsection
