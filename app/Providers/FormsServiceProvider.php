<?php

declare(strict_types=1);

namespace App\Providers;

use App\Forms\Contracts\PublicFormRepositoryInterface;
use App\Forms\Contracts\PublicFormServiceInterface;
use App\Forms\Repositories\PublicFormSubmissionRepository;
use App\Forms\Services\PublicFormService;
use Illuminate\Support\ServiceProvider;

class FormsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PublicFormRepositoryInterface::class, PublicFormSubmissionRepository::class);
        $this->app->bind(PublicFormServiceInterface::class, PublicFormService::class);
    }
}
