<?php

declare(strict_types=1);

namespace App\Providers;

use App\Homework\Contracts\HomeworkRepositoryInterface;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Repositories\HomeworkRepository;
use App\Homework\Services\HomeworkService;
use App\Models\HomeworkAssignment;
use App\Policies\HomeworkAssignmentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class HomeworkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HomeworkRepositoryInterface::class, HomeworkRepository::class);
        $this->app->bind(HomeworkServiceInterface::class, HomeworkService::class);
    }

    public function boot(): void
    {
        Gate::policy(HomeworkAssignment::class, HomeworkAssignmentPolicy::class);
    }
}
