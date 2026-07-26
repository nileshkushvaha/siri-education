<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\DTOs\AccountProfileSummaryData;
use App\Services\Account\AccountMenuService;
use App\Services\Account\AccountWalletSummaryService;
use App\Services\Account\PortalBadgeService;
use App\Services\FrontendPortalAudienceResolver;
use App\Services\Profile\ProfileService;
use Illuminate\View\View;

/**
 * Supplies the shared view data every Account Portal page needs, so
 * controllers stop repeating auth()->user() lookups and menu/profile
 * plumbing. Bound only to layouts.account (see AppServiceProvider::boot()).
 */
final class AccountPortalComposer
{
    public function __construct(
        private readonly AccountMenuService $menuService,
        private readonly ProfileService $profileService,
        private readonly PortalBadgeService $badges,
        private readonly FrontendPortalAudienceResolver $audiences,
        private readonly AccountWalletSummaryService $wallets,
    ) {}

    public function compose(View $view): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $user->loadMissing('profile.media');
        $badges = $this->badges->for($user);
        $audience = $this->audiences->resolve($user);

        $view->with([
            'accountAudience' => $audience,
            'accountMenu' => $this->menuService->items($user, $badges),
            'accountProfileSummary' => AccountProfileSummaryData::fromUser(
                $user,
                $this->profileService->completion($user),
            ),
            'accountNotificationCount' => $badges['notifications'],
            // Phase 37B: shared with the instructor dashboard widget so it
            // never re-queries the exact same pendingReviewCountForTeacher()
            // count PortalBadgeService already computed for the nav badge.
            'accountHomeworkPendingCount' => $badges['homework'],
            'accountWalletEnabled' => $this->wallets->enabledFor($audience),
            'accountWalletSummary' => $this->wallets->for($user, $audience),
            'accountReferralEnabled' => $this->wallets->referralEnabledFor($audience),
        ]);
    }
}
