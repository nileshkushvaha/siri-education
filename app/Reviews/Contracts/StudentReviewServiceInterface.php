<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\LessonReviewEligibility;
use App\Models\User;
use App\Reviews\DTOs\SubmitReviewResult;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Exceptions\ReviewEligibilityException;
use App\Reviews\Exceptions\ReviewValidationException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Single entry point for student review submission. Authorization
 * (the acting student must be the eligibility's own student) is
 * enforced here; SubmitLessonReviewAction handles the atomic
 * submission mechanics.
 */
interface StudentReviewServiceInterface
{
    /**
     * @throws AuthorizationException
     * @throws ReviewEligibilityException
     * @throws ReviewValidationException
     */
    public function submit(LessonReviewEligibility $eligibility, User $student, SubmitStudentReviewData $data): SubmitReviewResult;
}
