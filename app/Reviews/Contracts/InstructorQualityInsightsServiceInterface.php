<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\User;
use App\Reviews\DTOs\InstructorQualityInsightsData;
use App\Reviews\DTOs\PublicInstructorReviewData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Instructor-facing read boundary — every method takes the
 * authenticated instructor's own `User` and returns only that
 * instructor's data. Never accepts a bare instructor id from a
 * caller; the controller/Livewire layer is responsible for passing
 * `auth()->user()`, never a request-supplied id.
 */
interface InstructorQualityInsightsServiceInterface
{
    public function insightsFor(User $instructor): InstructorQualityInsightsData;

    /** @return LengthAwarePaginator<int, PublicInstructorReviewData> */
    public function recentReviewsFor(User $instructor, int $perPage = 10): LengthAwarePaginator;
}
