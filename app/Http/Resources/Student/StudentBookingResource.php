<?php

declare(strict_types=1);

namespace App\Http\Resources\Student;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Booking */
final class StudentBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Phase 24H.2A — GAP-013: the URL comes exclusively from the
        // authoritative service (viewer ownership + strict Active
        // lifecycle + visibility setting + booking/meeting status) —
        // this resource serializes only what the domain releases, and
        // an ineligible viewer's payload contains no URL key at all
        // (never an empty-but-present attribute).
        $joinUrl = app(BookingMeetingServiceInterface::class)
            ->studentJoinUrlFor($this->resource, $request->user());

        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'type' => [
                'key' => $this->type->key,
                'name' => $this->type->name,
            ],
            'teacher' => [
                'id' => $this->instructor_id,
                'name' => $this->instructor->name,
            ],
            'starts_at' => $this->starts_at->timezone($this->timezone)->toIso8601String(),
            'ends_at' => $this->ends_at->timezone($this->timezone)->toIso8601String(),
            'timezone' => $this->timezone,
            'payment_status' => $this->payment_status->value,
            'price' => $this->price,
            'currency' => $this->currency,
            'requires_payment' => $this->payment_status !== BookingPaymentStatus::NotRequired,
            'is_free_booking' => $this->price === null || (float) $this->price <= 0,
            'subject' => $this->meta['subject'] ?? null,
            'grade' => $this->meta['grade'] ?? null,
            'recurring_group' => $this->meta['recurring_group'] ?? null,
            'notes' => $this->notes,
            'meeting_url' => $this->when($joinUrl !== null, $joinUrl),
            // A join password (when the provider issued one) is not a
            // secret the same way host_url is — a student cannot join
            // without it. meeting.host_url/meeting.metadata are never
            // included here. Only present when the URL itself was released.
            'meeting_password' => $this->when(
                $joinUrl !== null && $this->meeting?->password !== null,
                $this->meeting?->password,
            ),
            'meeting_message' => $this->status === BookingStatus::Confirmed && $joinUrl === null
                ? 'Meeting link is being prepared.'
                : null,
        ];
    }
}
