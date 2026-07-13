<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\User;
use App\Reviews\DTOs\InstructorRatingSummaryData;
use App\Reviews\DTOs\PublicInstructorReviewData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Public-profile read boundary for reviews: a privacy-safe rating
 * summary (delegated straight to the Phase 17K aggregate — never
 * recalculated here) plus a paginated collection of masked review
 * cards. Never returns an Eloquent model to a caller.
 */
interface PublicInstructorReviewServiceInterface
{
    public function summaryFor(User $instructor): InstructorRatingSummaryData;

    /** @return LengthAwarePaginator<int, PublicInstructorReviewData> */
    public function paginatedReviewsFor(User $instructor, int $perPage = 10): LengthAwarePaginator;
}
