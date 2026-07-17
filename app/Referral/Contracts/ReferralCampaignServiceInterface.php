<?php

declare(strict_types=1);

namespace App\Referral\Contracts;

use App\Models\ReferralCampaign;
use App\Models\User;
use App\Referral\DTOs\ReferralCampaignData;
use App\Referral\Exceptions\ReferralException;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;

interface ReferralCampaignServiceInterface
{
    /**
     * Create a Draft campaign. Validates every reward rule; audited.
     *
     * @throws ReferralException
     * @throws AuthorizationException
     */
    public function create(ReferralCampaignData $data, User $admin): ReferralCampaign;

    /**
     * Update an editable (Draft or Paused) campaign's rules; audited
     * with before/after values.
     *
     * @throws ReferralException
     * @throws AuthorizationException
     */
    public function update(ReferralCampaign $campaign, ReferralCampaignData $data, User $admin): ReferralCampaign;

    /**
     * Lifecycle transitions — the ONLY writers of status. Each requires
     * ManageReferralCampaigns, a non-empty reason, and a transition the
     * status matrix allows; each is audited with the acting admin.
     */
    public function activate(ReferralCampaign $campaign, User $admin, string $reason): ReferralCampaign;

    public function pause(ReferralCampaign $campaign, User $admin, string $reason): ReferralCampaign;

    public function resume(ReferralCampaign $campaign, User $admin, string $reason): ReferralCampaign;

    public function complete(ReferralCampaign $campaign, User $admin, string $reason): ReferralCampaign;

    public function archive(ReferralCampaign $campaign, User $admin, string $reason): ReferralCampaign;

    /**
     * Read-only resolution for Phase 19D reward evaluation: the single
     * Active campaign whose half-open UTC window [starts_at, ends_at)
     * covers the instant and whose country scope admits $countryId
     * (campaigns with no country rows admit every country; a null
     * $countryId only matches all-country campaigns). Overlap is
     * prevented at activation; if data nevertheless contains multiple
     * matches, the earliest starts_at (then lowest id) wins —
     * deterministically, never "first row returned".
     */
    public function activeCampaignFor(DateTimeInterface $atUtc, ?int $countryId): ?ReferralCampaign;
}
