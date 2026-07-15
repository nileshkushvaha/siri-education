<?php

declare(strict_types=1);

namespace App\Http\Resources\Student;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingStatus;
use App\Models\Booking;
use App\Settings\MeetingSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Booking */
final class StudentBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'meeting_url' => $this->when(
                $this->meetingVisible(),
                $this->meeting?->join_url,
            ),
            // A join password (when the provider issued one) is not a
            // secret the same way host_url is — a student cannot join
            // without it. meeting.host_url/meeting.metadata are never
            // included here.
            'meeting_password' => $this->when(
                $this->meetingVisible() && $this->meeting?->password !== null,
                $this->meeting?->password,
            ),
            'meeting_message' => $this->status === BookingStatus::Confirmed && ! $this->meetingVisible()
                ? 'Meeting link is being prepared.'
                : null,
        ];
    }

    /** Student-visible only once confirmed, created, and the admin hasn't turned off student visibility. */
    private function meetingVisible(): bool
    {
        return $this->status === BookingStatus::Confirmed
            && $this->meeting?->status === MeetingStatus::Created
            && app(MeetingSettings::class)->student_join_url_visible;
    }
}
