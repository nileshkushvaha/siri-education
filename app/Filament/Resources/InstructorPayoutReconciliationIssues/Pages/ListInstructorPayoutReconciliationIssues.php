<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutReconciliationIssues\Pages;

use App\Earnings\Enums\PayoutReconciliationIssueStatus;
use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\InstructorPayoutReconciliationIssues\InstructorPayoutReconciliationIssueResource;
use App\Filament\Resources\InstructorWithdrawalRequests\InstructorWithdrawalRequestResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\InstructorPayoutReconciliationIssue;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListInstructorPayoutReconciliationIssues extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = InstructorPayoutReconciliationIssueResource::class;

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
        return StatusTabs::forEnum(InstructorPayoutReconciliationIssue::class, PayoutReconciliationIssueStatus::class);
    }
}
