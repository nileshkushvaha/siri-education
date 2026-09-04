@extends('layouts.account')

@section('title', 'Lesson recording — ' . config('app.name'))

@section('account-breadcrumbs')
    <x-account.breadcrumb :crumbs="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'My Bookings', 'url' => route('dashboard.my-bookings')],
        ['label' => 'Recording'],
    ]" />
@endsection

@section('account-content')

    {{--
        Student playback (SRS §12.20). What this page deliberately does NOT
        contain: any provider name, storage backend, file id, or download
        control. The video source is the application's own stream route,
        which re-authorizes every request; the watermark carries only the
        platform name, the booking's public reference and the clock. Blocking the
        context menu and hiding the download control are deterrents against
        casual saving — not security. Security is the policy behind the
        stream route.
    --}}

    <div class="mb-6">
        <h1 class="text-xl font-bold text-fg-strong">Lesson recording</h1>
        <p class="text-fg-muted text-sm mt-1">
            {{ $booking?->type?->name ?? 'Session' }}
            @if($booking?->instructor) with {{ $booking->instructor->name }} @endif
            @if($booking?->starts_at) &middot; {{ viewer_datetime_labelled($booking->starts_at) }} @endif
        </p>
    </div>

    <div class="overflow-hidden rounded-2xl border border-edge" data-account-card>
        <div
            x-data="recordingPlayer({ platform: @js($watermark['platform']), reference: @js($watermark['reference']), moveSeconds: @js($watermark['moveSeconds']) })"
            x-ref="wrapper"
            @contextmenu.prevent
            @fullscreenchange.window="syncFullscreen()"
            @webkitfullscreenchange.window="syncFullscreen()"
            class="relative w-full bg-black select-none"
            :class="fullscreen ? 'flex items-center justify-center h-screen' : 'aspect-video'"
            data-recording-player
        >
            <video
                x-ref="video"
                class="h-full w-full bg-black"
                src="{{ route('dashboard.recordings.stream', $recording) }}"
                controls
                controlsList="nodownload nofullscreen noremoteplayback"
                disablePictureInPicture
                disableRemotePlayback
                playsinline
                preload="metadata"
                @loadedmetadata="ready = true"
                @play="playing = true"
                @pause="playing = false"
            >
                Your browser does not support in-page video playback.
            </video>

            {{-- Dynamic viewer watermark: repositioned on a timer so it
                 cannot be cropped out consistently. Pointer events pass
                 through so the native controls stay usable beneath it. --}}
            <div
                class="pointer-events-none absolute z-10 rounded-md bg-black/45 px-2.5 py-1.5 text-[11px] font-semibold leading-tight text-white/85 shadow-sm backdrop-blur-[1px] motion-safe:transition-all motion-safe:duration-700 sm:text-xs"
                :style="watermarkStyle()"
                aria-hidden="true"
                data-recording-watermark
            >
                <span x-text="platform"></span>
                <span class="mx-1 opacity-60">&middot;</span>
                <span x-text="reference"></span>
                <span class="mx-1 opacity-60">&middot;</span>
                <span x-text="clock"></span>
            </div>

            <button
                type="button"
                x-show="canFullscreen"
                x-cloak
                @click="toggleFullscreen()"
                class="absolute right-3 top-3 z-20 inline-flex h-9 w-9 items-center justify-center rounded-lg bg-black/55 text-white/90 transition hover:bg-black/75 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
                :aria-label="fullscreen ? 'Exit fullscreen' : 'Fullscreen'"
                :title="fullscreen ? 'Exit fullscreen' : 'Fullscreen'"
            >
                <svg x-show="!fullscreen" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4h4M20 8V4h-4M4 16v4h4M20 16v4h-4"/></svg>
                <svg x-show="fullscreen" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 4v4H4M16 4v4h4M8 20v-4H4M16 20v-4h4"/></svg>
            </button>
        </div>

        <div class="px-5 py-4 sm:px-6 text-sm text-fg-muted">
            <p>
                This recording is available only in your {{ config('app.name') }} account and is intended for your own revision.
                @if($recording->expires_at)
                    It will be removed after {{ viewer_datetime_labelled($recording->expires_at) }}.
                @endif
            </p>
            <p class="mt-2 text-xs text-fg-faint">Booking {{ $booking?->reference }}</p>
        </div>
    </div>

@endsection
