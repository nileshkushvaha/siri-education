<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralCampaigns\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\ReferralCampaigns\Pages\Concerns\BuildsCampaignData;
use App\Filament\Resources\ReferralCampaigns\ReferralCampaignResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\ReferralCampaign;
use App\Referral\Contracts\ReferralCampaignServiceInterface;
use App\Referral\Exceptions\ReferralException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditReferralCampaign extends EditRecord
{
    use BuildsCampaignData;
    use HasSectionBreadcrumb;

    protected static string $resource = ReferralCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Referral Campaigns'),
        ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ReferralCampaign $campaign */
        $campaign = $this->getRecord();

        $data['eligible_countries'] = $campaign->eligibleCountries()->pluck('countries.id')->all();

        return $data;
    }

    /** Rule edits flow through the service — Draft/Paused only, audited. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(ReferralCampaignServiceInterface::class)
                ->update($record, $this->campaignDataFrom($data), auth()->user());
        } catch (ReferralException $e) {
            Notification::make()->title('Campaign not updated')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
