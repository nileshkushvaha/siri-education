<?php

declare(strict_types=1);

namespace App\Http\Resources\Guest;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Booking */
final class GuestBookingResource extends JsonResource
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
            'starts_at' => $this->starts_at->timezone($this->timezone)->toIso8601String(),
            'ends_at' => $this->ends_at->timezone($this->timezone)->toIso8601String(),
            'timezone' => $this->timezone,
            'guest_name' => $this->guest_name,
            'payment_status' => $this->payment_status->value,
            'price' => $this->price,
            'currency' => $this->currency,
            'requires_payment' => $this->payment_status !== BookingPaymentStatus::NotRequired,
            'is_free_booking' => $this->price === null || (float) $this->price <= 0,
            'subject' => $this->meta['subject'] ?? null,
            'grade' => $this->meta['grade'] ?? null,
            'notes' => $this->notes,
            'meeting_url' => $this->when(
                $this->status === BookingStatus::Confirmed && $this->meeting_url !== null,
                $this->meeting_url,
            ),
            'cancellation' => $this->when($this->status === BookingStatus::Cancelled, fn (): array => [
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
                'reason' => $this->cancellation_reason,
            ]),
        ];
    }
}
