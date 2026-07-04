<?php

declare(strict_types=1);

namespace App\Http\Resources\Guest;

use App\Models\BookingType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BookingType */
final class BookingTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'is_paid' => $this->is_paid,
            'price' => $this->when($this->is_paid, $this->price),
            'currency' => $this->when($this->is_paid, $this->currency),
            'requires_approval' => $this->requires_approval,
        ];
    }
}
