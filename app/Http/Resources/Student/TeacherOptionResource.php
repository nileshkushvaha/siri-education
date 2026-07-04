<?php

declare(strict_types=1);

namespace App\Http\Resources\Student;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class TeacherOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'headline' => $this->profile?->headline,
            'timezone' => $this->profile?->timezone,
        ];
    }
}
