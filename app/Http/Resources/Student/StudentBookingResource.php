<?php

declare(strict_types=1);

namespace App\Http\Resources\Student;

use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
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
                'id' => $this->host_id,
                'name' => $this->host->name,
            ],
            'starts_at' => $this->starts_at->timezone($this->timezone)->toIso8601String(),
            'ends_at' => $this->ends_at->timezone($this->timezone)->toIso8601String(),
            'timezone' => $this->timezone,
            'payment_status' => $this->payment_status->value,
            'price' => $this->price,
            'currency' => $this->currency,
            'subject' => $this->meta['subject'] ?? null,
            'grade' => $this->meta['grade'] ?? null,
            'recurring_group' => $this->meta['recurring_group'] ?? null,
            'notes' => $this->notes,
            'meeting_url' => $this->when(
                $this->status === BookingStatus::Confirmed && $this->meeting_url !== null,
                $this->meeting_url,
            ),
        ];
    }
}
