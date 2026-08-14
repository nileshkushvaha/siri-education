<?php

declare(strict_types=1);

namespace App\Http\Resources\Student;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Booking */
final class StudentBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The URL comes exclusively from the
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
            // Phase 4E.3 (PKG-AUD-011) — this field answers "does the
            // student still owe money on this booking?", so it must be
            // the payable predicate, not "is it non-free". The old
            // `!== NotRequired` reading told a student their PREPAID
            // package lesson still required payment. isPayable() is
            // already the owner of that question: true for
            // Pending/Failed, false for Paid, NotRequired and
            // PackageFunded alike.
            'requires_payment' => $this->payment_status->isPayable(),
            // The real commercial value is unchanged and still exposed
            // above; only the collection expectation differs. The label
            // comes from the enum so there is no second funding-label
            // system to keep in step.
            'payment_status_label' => $this->payment_status->label(),
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
