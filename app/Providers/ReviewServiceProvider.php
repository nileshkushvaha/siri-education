<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\LessonReviewEligibility;
use App\Policies\LessonReviewEligibilityPolicy;
use App\Reviews\Contracts\LessonReviewEligibilityRepositoryInterface;
use App\Reviews\Contracts\ReviewEligibilityServiceInterface;
use App\Reviews\Repositories\LessonReviewEligibilityRepository;
use App\Reviews\Services\ReviewEligibilityService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ReviewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LessonReviewEligibilityRepositoryInterface::class, LessonReviewEligibilityRepository::class);
        $this->app->singleton(ReviewEligibilityServiceInterface::class, ReviewEligibilityService::class);
    }

    public function boot(): void
    {
        Gate::policy(LessonReviewEligibility::class, LessonReviewEligibilityPolicy::class);
    }
}
