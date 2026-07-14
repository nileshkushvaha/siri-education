<?php

declare(strict_types=1);

namespace App\Providers;

use App\Feedback\Contracts\InstructorStudentFeedbackRepositoryInterface;
use App\Feedback\Contracts\InstructorStudentFeedbackServiceInterface;
use App\Feedback\Repositories\InstructorStudentFeedbackRepository;
use App\Feedback\Services\InstructorStudentFeedbackService;
use App\Models\InstructorStudentFeedback;
use App\Policies\InstructorStudentFeedbackPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class FeedbackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InstructorStudentFeedbackRepositoryInterface::class, InstructorStudentFeedbackRepository::class);
        $this->app->singleton(InstructorStudentFeedbackServiceInterface::class, InstructorStudentFeedbackService::class);
    }

    public function boot(): void
    {
        Gate::policy(InstructorStudentFeedback::class, InstructorStudentFeedbackPolicy::class);
    }
}
