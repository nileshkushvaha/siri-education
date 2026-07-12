<?php

declare(strict_types=1);

namespace App\Providers;

use App\Lessons\Contracts\LessonAttendanceRepositoryInterface;
use App\Lessons\Contracts\LessonAttendanceServiceInterface;
use App\Lessons\Contracts\LessonFinalizationServiceInterface;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\Repositories\LessonAttendanceRepository;
use App\Lessons\Repositories\LessonRepository;
use App\Lessons\Services\LessonAttendanceService;
use App\Lessons\Services\LessonFinalizationService;
use App\Lessons\Services\LessonLifecycleService;
use App\Lessons\Services\LessonOutcomeService;
use Illuminate\Support\ServiceProvider;

class LessonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LessonRepositoryInterface::class, LessonRepository::class);
        $this->app->singleton(LessonLifecycleServiceInterface::class, LessonLifecycleService::class);
        $this->app->singleton(LessonAttendanceRepositoryInterface::class, LessonAttendanceRepository::class);
        $this->app->singleton(LessonAttendanceServiceInterface::class, LessonAttendanceService::class);
        $this->app->singleton(LessonOutcomeServiceInterface::class, LessonOutcomeService::class);
        $this->app->singleton(LessonFinalizationServiceInterface::class, LessonFinalizationService::class);
    }
}
