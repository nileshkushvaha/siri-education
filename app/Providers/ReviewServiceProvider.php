<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\ReviewReport;
use App\Policies\LessonReviewEligibilityPolicy;
use App\Policies\LessonReviewPolicy;
use App\Policies\ReviewReportPolicy;
use App\Reviews\Contracts\InstructorQualityInsightsServiceInterface;
use App\Reviews\Contracts\InstructorRatingAggregateRepositoryInterface;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Contracts\LessonReviewEligibilityRepositoryInterface;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\Contracts\PublicInstructorReviewServiceInterface;
use App\Reviews\Contracts\ReviewEligibilityServiceInterface;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\ReviewRatingContributionRepositoryInterface;
use App\Reviews\Contracts\ReviewReportRepositoryInterface;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\Repositories\InstructorRatingAggregateRepository;
use App\Reviews\Repositories\LessonReviewEligibilityRepository;
use App\Reviews\Repositories\LessonReviewRepository;
use App\Reviews\Repositories\ReviewRatingContributionRepository;
use App\Reviews\Repositories\ReviewReportRepository;
use App\Reviews\Services\InstructorQualityInsightsService;
use App\Reviews\Services\InstructorRatingAggregateService;
use App\Reviews\Services\PublicInstructorReviewService;
use App\Reviews\Services\ReviewEligibilityService;
use App\Reviews\Services\ReviewModerationService;
use App\Reviews\Services\ReviewReportService;
use App\Reviews\Services\StudentReviewService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ReviewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LessonReviewEligibilityRepositoryInterface::class, LessonReviewEligibilityRepository::class);
        $this->app->singleton(ReviewEligibilityServiceInterface::class, ReviewEligibilityService::class);
        $this->app->singleton(LessonReviewRepositoryInterface::class, LessonReviewRepository::class);
        $this->app->singleton(StudentReviewServiceInterface::class, StudentReviewService::class);
        $this->app->singleton(ReviewModerationServiceInterface::class, ReviewModerationService::class);
        $this->app->singleton(InstructorRatingAggregateRepositoryInterface::class, InstructorRatingAggregateRepository::class);
        $this->app->singleton(ReviewRatingContributionRepositoryInterface::class, ReviewRatingContributionRepository::class);
        $this->app->singleton(InstructorRatingAggregateServiceInterface::class, InstructorRatingAggregateService::class);
        $this->app->singleton(PublicInstructorReviewServiceInterface::class, PublicInstructorReviewService::class);
        $this->app->singleton(ReviewReportRepositoryInterface::class, ReviewReportRepository::class);
        $this->app->singleton(ReviewReportServiceInterface::class, ReviewReportService::class);
        $this->app->singleton(InstructorQualityInsightsServiceInterface::class, InstructorQualityInsightsService::class);
    }

    public function boot(): void
    {
        Gate::policy(LessonReviewEligibility::class, LessonReviewEligibilityPolicy::class);
        Gate::policy(LessonReview::class, LessonReviewPolicy::class);
        Gate::policy(ReviewReport::class, ReviewReportPolicy::class);
    }
}
