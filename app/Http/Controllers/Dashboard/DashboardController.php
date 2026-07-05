<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Instructor\InstructorOnboardingService;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly InstructorOnboardingService $instructorOnboarding,
    ) {}

    /**
     * Admin-portal users are kept off this route entirely by the
     * frontend.portal middleware (see routes/web.php) — by the time this
     * runs, the user is guaranteed to belong to the Frontend Portal.
     *
     * Shared Account Portal data (menu, profile summary, notification
     * count) comes from AccountPortalComposer, bound to layouts.account.
     */
    public function __invoke(): View
    {
        return view('dashboard.index', [
            'instructorOnboarding' => $this->instructorOnboarding->progress(auth()->user()),
        ]);
    }
}
