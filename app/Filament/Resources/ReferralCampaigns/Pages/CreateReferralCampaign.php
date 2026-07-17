<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralCampaigns\Pages;

use App\Filament\Resources\ReferralCampaigns\Pages\Concerns\BuildsCampaignData;
use App\Filament\Resources\ReferralCampaigns\ReferralCampaignResource;
use App\Referral\Contracts\ReferralCampaignServiceInterface;
use App\Referral\Exceptions\ReferralException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateReferralCampaign extends CreateRecord
{
    use BuildsCampaignData;

    protected static string $resource = ReferralCampaignResource::class;

    /** Creation flows through the service — validated, audited, Draft. */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ReferralCampaignServiceInterface::class)
                ->create($this->campaignDataFrom($data), auth()->user());
        } catch (ReferralException $e) {
            Notification::make()->title('Campaign not created')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
