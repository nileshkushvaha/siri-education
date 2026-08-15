<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutAttempts\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\InstructorPayoutAttempts\InstructorPayoutAttemptResource;
use App\Filament\Resources\InstructorWithdrawalRequests\InstructorWithdrawalRequestResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use Filament\Resources\Pages\ListRecords;

class ListInstructorPayoutAttempts extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = InstructorPayoutAttemptResource::class;

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
