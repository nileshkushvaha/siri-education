<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\SuspiciousActivityFlag;
use App\Policies\SuspiciousActivityFlagPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ComplianceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(SuspiciousActivityFlag::class, SuspiciousActivityFlagPolicy::class);
    }
}
