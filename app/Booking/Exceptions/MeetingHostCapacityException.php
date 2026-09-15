<?php

declare(strict_types=1);

namespace App\Booking\Exceptions;

use Carbon\CarbonImmutable;

/**
 * No platform host can take this booking's occupied interval. Raised
 * INSIDE the booking transaction, so the booking rolls back, nothing is
 * charged and no hold is created — unless the operator has chosen a
 * fallback provider (MeetingSettings::zoom_capacity_fallback_provider),
 * in which case MeetingHostCapacityService::reserveOrFallback() pins the
 * booking to that provider instead of letting this escape. The message
 * is safe to show to the person booking; detail() carries the technical
 * window for audit entries and failure reasons.
 */
final class MeetingHostCapacityException extends BookingException
{
    private function __construct(
        string $message,
        /** Whether a configured fallback provider may take the booking instead of refusing it. */
        public readonly bool $mayFallBack,
        private readonly string $detail,
    ) {
        parent::__construct($message);
    }

    public static function exhausted(string $provider, CarbonImmutable $from, CarbonImmutable $until): self
    {
        return new self(
            'This time is fully booked on our video platform. Please choose another time.',
            mayFallBack: true,
            detail: sprintf(
                'No %s host is available between %s and %s (UTC).',
                ucfirst($provider),
                $from->utc()->format('Y-m-d H:i'),
                $until->utc()->format('Y-m-d H:i'),
            ),
        );
    }

    public static function noHostRegistered(string $provider): self
    {
        $detail = sprintf(
            'No %s host is registered for capacity reservation. Register one with meetings:zoom-hosts:register or disable capacity reservation.',
            ucfirst($provider),
        );

        return new self($detail, mayFallBack: true, detail: $detail);
    }

    /** The operator switched reservation off deliberately: never substituted, always refused. */
    public static function reservationDisabled(): self
    {
        $detail = 'Zoom lessons are not being accepted right now: Zoom host capacity reservation is switched off. '
            .'Set the default meeting provider to Google Meet, or enable capacity reservation after a clean preflight.';

        return new self($detail, mayFallBack: false, detail: $detail);
    }

    /** A meeting already lives on this host, so participants hold its link: never substituted. */
    public static function hostUnavailable(string $provider, string $hostReference): self
    {
        $detail = sprintf(
            'The %s host this meeting already lives on (%s) has no capacity at the requested time.',
            ucfirst($provider),
            $hostReference,
        );

        return new self($detail, mayFallBack: false, detail: $detail);
    }

    /** The technical reason (provider, host, UTC window) — for audit trails and failure reasons, not for students. */
    public function detail(): string
    {
        return $this->detail;
    }
}
