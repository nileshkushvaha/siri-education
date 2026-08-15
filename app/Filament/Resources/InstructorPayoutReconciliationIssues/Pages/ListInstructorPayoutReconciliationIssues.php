<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutReconciliationIssues\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\InstructorPayoutReconciliationIssues\InstructorPayoutReconciliationIssueResource;
use App\Filament\Resources\InstructorWithdrawalRequests\InstructorWithdrawalRequestResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use Filament\Resources\Pages\ListRecords;

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
}
