<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Student;

use App\Referral\Contracts\ReferralCodeServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Settings\FeatureSettings;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The student's Refer a Friend panel. Lazily generates the student's
 * code on first visit via ReferralCodeService (never in a migration)
 * and renders the shareable link from the named registration route.
 * Phase 19D adds source-backed reward totals and paginated history —
 * referred students appear only through the ReferredStudentMask rule,
 * amounts stay currency-separated, and hold reasons are never shown.
 */
final class ReferFriend extends Component
{
    use WithPagination;

    public string $code = '';

    public string $link = '';

    public bool $codeDisabled = false;

    public function mount(ReferralCodeServiceInterface $codes, FeatureSettings $features): void
    {
        abort_unless($features->referral_enabled, 404);

        $user = auth()->user();

        abort_unless($user?->hasRole('student'), 403);

        $referralCode = $codes->getOrCreateForStudent($user);

        $this->code = $referralCode->code;
        $this->link = route('auth.register', ['ref' => $referralCode->code]);
        $this->codeDisabled = ! $referralCode->isActive();
    }

    public function render(ReferralRewardServiceInterface $rewards): View
    {
        $user = auth()->user();

        return view('livewire.frontend.student.refer-friend', [
            'shareText' => sprintf(
                'Join me on %s! Sign up with my referral code %s: %s',
                config('app.name'),
                $this->code,
                $this->link,
            ),
            'summary' => $rewards->summaryForReferrer($user),
            'history' => $rewards->historyForReferrer($user),
        ]);
    }
}
