<?php

declare(strict_types=1);

namespace App\Http\Resources\Booking;

use App\Booking\DTOs\TimeSlotData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimeSlotData */
final class TimeSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'remaining_capacity' => $this->remainingCapacity,
        ];
    }
}
