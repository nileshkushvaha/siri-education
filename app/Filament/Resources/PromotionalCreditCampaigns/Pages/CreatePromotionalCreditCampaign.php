<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditCampaigns\Pages;

use App\Filament\Resources\PromotionalCreditCampaigns\Pages\Concerns\BuildsPromotionalCreditCampaignData;
use App\Filament\Resources\PromotionalCreditCampaigns\PromotionalCreditCampaignResource;
use App\PromotionalCredits\Exceptions\PromotionalCreditException;
use App\PromotionalCredits\Services\PromotionalCreditService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreatePromotionalCreditCampaign extends CreateRecord
{
    use BuildsPromotionalCreditCampaignData;

    protected static string $resource = PromotionalCreditCampaignResource::class;

    /** Creation flows through the service — validated, audited, Draft. */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PromotionalCreditService::class)
                ->createCampaign($this->campaignDataFrom($data), auth()->user());
        } catch (PromotionalCreditException $e) {
            Notification::make()->title('Campaign not created')->body($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
