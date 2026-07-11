<?php

declare(strict_types=1);

namespace App\Providers;

use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\Repositories\LessonRepository;
use App\Lessons\Services\LessonLifecycleService;
use Illuminate\Support\ServiceProvider;

class LessonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LessonRepositoryInterface::class, LessonRepository::class);
        $this->app->singleton(LessonLifecycleServiceInterface::class, LessonLifecycleService::class);
    }
}
