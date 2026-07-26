<?php

declare(strict_types=1);

namespace App\Reviews\Services;

use App\Models\LessonReviewEligibility;
use App\Models\User;
use App\Reviews\Actions\SubmitLessonReviewAction;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitReviewResult;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;

final class StudentReviewService implements StudentReviewServiceInterface
{
    public function __construct(
        private readonly SubmitLessonReviewAction $submit,
        private readonly StudentLifecycleService $lifecycle,
    ) {}

    public function submit(LessonReviewEligibility $eligibility, User $student, SubmitStudentReviewData $data): SubmitReviewResult
    {
        if (! $student->can('submitReview', $eligibility)) {
            throw new AuthorizationException('You may not submit a review for this eligibility.');
        }

        // Review submission is an interactive student action; requires
        // an Active student at submission time. Additional to (never
        // replacing) the existing eligibility/ownership/one-review
        // rules above and inside the action.
        $this->lifecycle->assertEligibleForStudentAction($student);

        return $this->submit->execute($eligibility, $student, $data);
    }
}
