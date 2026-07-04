<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Guest;

use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\Guest\BookingTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Read-only lookups powering the public booking wizard. */
final class GuestCatalogController extends Controller
{
    public function __construct(
        private readonly BookingTypeRepositoryInterface $types,
        private readonly TeacherCandidateRepositoryInterface $teachers,
    ) {}

    public function types(): AnonymousResourceCollection
    {
        return BookingTypeResource::collection($this->types->allActive());
    }

    public function subjects(): JsonResponse
    {
        return response()->json(['data' => $this->teachers->availableSubjects()]);
    }
}
