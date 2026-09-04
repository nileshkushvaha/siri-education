<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutMethods\Pages;

use App\Earnings\Enums\PayoutMethodStatus;
use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\InstructorPayoutMethods\InstructorPayoutMethodResource;
use App\Filament\Resources\InstructorWithdrawalRequests\InstructorWithdrawalRequestResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\InstructorPayoutMethod;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListInstructorPayoutMethods extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = InstructorPayoutMethodResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(InstructorWithdrawalRequestResource::class, 'Back to Withdrawal Requests'),
        ]);
    }

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::instructorFinance();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(InstructorPayoutMethod::class, PayoutMethodStatus::class);
    }
}
