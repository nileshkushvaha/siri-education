<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instructor;

use App\Contracts\InstructorEligibilityServiceInterface;
use App\Enums\InstructorStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Faq\FaqService;
use Illuminate\View\View;

/**
 * Public "Become an Instructor" entry point. Read-only —
 * never starts an application, assigns a role, or writes anything.
 * Guests see generic marketing copy; an authenticated user sees
 * status-aware messaging built from data already loaded on their own
 * request (auth()->user()), so a guest visit never touches
 * users/profiles/instructor tables at all.
 */
final class InstructorApplicationController extends Controller
{
    public function __construct(
        private readonly InstructorEligibilityServiceInterface $eligibility,
        private readonly FaqService $faqService,
    ) {}

    public function show(): View
    {
        $user = auth()->user();

        return view('instructor-apply.show', [
            'existingApplication' => $user ? $this->existingApplicationMessage($user) : null,
            'eligibility' => $user && $user->profile?->instructor_status === null
                ? $this->eligibility->evaluate($user)
                : null,
            'faqs' => $this->faqService->publicFaqsForCategory('Become an Instructor'),
        ]);
    }

    /** @return array{heading: string, message: string}|null */
    private function existingApplicationMessage(User $user): ?array
    {
        $status = $user->profile?->instructor_status;

        if ($status === null) {
            return null;
        }

        return match ($status) {
            InstructorStatus::Draft => [
                'heading' => 'Continue your application',
                'message' => 'You already started an instructor application. Continue where you left off.',
            ],
            InstructorStatus::DocumentsPending => [
                'heading' => 'Additional documents requested',
                'message' => 'We requested additional documents for your application. Continue your application to upload them.',
            ],
            InstructorStatus::Submitted, InstructorStatus::UnderReview, InstructorStatus::InterviewRequired => [
                'heading' => 'Application under review',
                'message' => 'Your instructor application is under review. We will notify you once a decision has been made.',
            ],
            InstructorStatus::Approved => [
                'heading' => 'Application approved',
                'message' => 'Your instructor application has been approved.',
            ],
            InstructorStatus::Active => [
                'heading' => 'You are already an instructor',
                'message' => 'Your instructor profile is already active and visible to students.',
            ],
            InstructorStatus::Vacation => [
                'heading' => 'Instructor profile on vacation',
                'message' => 'Your instructor profile is currently on vacation mode.',
            ],
            InstructorStatus::Suspended => [
                'heading' => 'Instructor profile suspended',
                'message' => 'Your instructor profile is currently suspended. Contact support for details.',
            ],
            InstructorStatus::Archived => [
                'heading' => 'Instructor profile archived',
                'message' => 'Your instructor profile has been archived. Contact support if you would like to reapply.',
            ],
            InstructorStatus::Rejected => [
                'heading' => 'Application not approved',
                'message' => 'Your previous instructor application was not approved. Contact support for details.',
            ],
        };
    }
}
