<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditCampaigns\Pages;

use App\Filament\Resources\PromotionalCreditCampaigns\Pages\Concerns\BuildsPromotionalCreditCampaignData;
use App\Filament\Resources\PromotionalCreditCampaigns\PromotionalCreditCampaignResource;
use App\PromotionalCredits\Exceptions\PromotionalCreditException;
use App\PromotionalCredits\Services\PromotionalCreditService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditPromotionalCreditCampaign extends EditRecord
{
    use BuildsPromotionalCreditCampaignData;

    protected static string $resource = PromotionalCreditCampaignResource::class;

    /** Rule edits flow through the service — Draft/Paused only, audited. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(PromotionalCreditService::class)
                ->updateCampaign($record, $this->campaignDataFrom($data), auth()->user());
        } catch (PromotionalCreditException $e) {
            Notification::make()->title('Campaign not updated')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
