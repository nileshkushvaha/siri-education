{{--
    The ONE student join action — <x-student.join-action :state="$joinState" :booking="$booking" />

    Renders what BookingMeetingService::studentJoinStatesFor() released for
    this viewer and nothing else: the SIRI gateway button while the window
    is open, otherwise when it opens / that the link is being prepared /
    when it closed. Never reads meeting->join_url. Polling is the parent
    Livewire component's job (wire:poll is per component).

    Props:
        state:        App\Booking\DTOs\StudentJoinState
        booking:      App\Models\Booking
        size:         x-ui.button size (default: sm)
        compact:      true for list rows — button or one short line, no explanations
        showPasscode: show the meeting passcode next to the button (detail page only)
--}}
@props([
    'state',
    'booking',
    'size' => 'sm',
    'compact' => false,
    'showPasscode' => false,
])

@php
    use App\Booking\Enums\MeetingJoinAvailability;
@endphp

<div {{ $attributes }} data-join-state="{{ $state->availability->value }}">
    @if($state->isAvailable())
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button :href="$state->joinUrl" target="_blank" rel="noopener" :size="$size">Join the lesson</x-ui.button>
            @if($showPasscode && $state->passcode)
                <p class="text-xs text-fg-muted">Passcode <span class="font-semibold text-fg-strong">{{ $state->passcode }}</span></p>
            @endif
        </div>
        @unless($compact)
            @if($state->ended && $state->closesAt)
                <p class="mt-2 text-sm text-fg-muted">Scheduled time ended. Joining closes at {{ viewer_time($state->closesAt) }}.</p>
            @elseif($booking->hasStarted())
                <p class="mt-2 text-sm text-fg-muted">This lesson is in progress.</p>
            @endif
        @endunless
    @elseif($state->availability === MeetingJoinAvailability::TooEarly && $state->opensAt)
        @if($compact)
            <p class="text-xs text-fg-muted">Join opens {{ viewer_time($state->opensAt) }}</p>
        @else
            <p class="text-sm text-fg-muted">Joining opens at {{ viewer_datetime($state->opensAt, 'D, j M · g:i A') }}.</p>
        @endif
    @elseif($state->availability === MeetingJoinAvailability::NotReady && ! $state->ended)
        <p class="{{ $compact ? 'text-xs' : 'text-sm' }} text-fg-muted">The meeting link is being prepared.</p>
    @elseif($state->ended && $state->closesAt)
        <p class="{{ $compact ? 'text-xs' : 'text-sm' }} text-fg-muted">Joining closed at {{ viewer_time($state->closesAt) }}.</p>
    @endif
</div>
