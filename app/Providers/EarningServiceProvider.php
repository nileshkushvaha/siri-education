<?php

declare(strict_types=1);

namespace App\Providers;

use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Repositories\InstructorEarningRepository;
use App\Earnings\Services\InstructorEarningService;
use Illuminate\Support\ServiceProvider;

class EarningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InstructorEarningRepositoryInterface::class, InstructorEarningRepository::class);
        $this->app->singleton(InstructorEarningServiceInterface::class, InstructorEarningService::class);
    }
}
