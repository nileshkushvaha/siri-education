<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Policies\LessonReviewEligibilityPolicy;
use App\Policies\LessonReviewPolicy;
use App\Reviews\Contracts\LessonReviewEligibilityRepositoryInterface;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;
use App\Reviews\Contracts\ReviewEligibilityServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\Repositories\LessonReviewEligibilityRepository;
use App\Reviews\Repositories\LessonReviewRepository;
use App\Reviews\Services\ReviewEligibilityService;
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
    }

    public function boot(): void
    {
        Gate::policy(LessonReviewEligibility::class, LessonReviewEligibilityPolicy::class);
        Gate::policy(LessonReview::class, LessonReviewPolicy::class);
    }
}
