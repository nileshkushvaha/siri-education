<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditCampaigns\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\PromotionalCreditCampaigns\Pages\Concerns\BuildsPromotionalCreditCampaignData;
use App\Filament\Resources\PromotionalCreditCampaigns\PromotionalCreditCampaignResource;
use App\Filament\Support\Presentation\BackAction;
use App\PromotionalCredits\Exceptions\PromotionalCreditException;
use App\PromotionalCredits\Services\PromotionalCreditService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditPromotionalCreditCampaign extends EditRecord
{
    use BuildsPromotionalCreditCampaignData;
    use HasSectionBreadcrumb;

    protected static string $resource = PromotionalCreditCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Promotional Credit Campaigns'),
        ]);
    }

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
