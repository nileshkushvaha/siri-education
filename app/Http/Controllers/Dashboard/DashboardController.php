<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\PortalAudience;
use App\Http\Controllers\Controller;
use App\Services\FrontendPortalAudienceResolver;
use App\Services\Student\StudentProfileCompletenessService;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly FrontendPortalAudienceResolver $audiences,
        private readonly StudentProfileCompletenessService $profileCompleteness,
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
        $audience = $this->audiences->resolve(auth()->user());

        abort_if($audience === PortalAudience::AdminOrUnsupported, 403);

        return view('dashboard.index', [
            'portalAudience' => $audience,
            'profileMissing' => $audience === PortalAudience::Student
                ? $this->profileCompleteness->missing(auth()->user())
                : [],
        ]);
    }
}
