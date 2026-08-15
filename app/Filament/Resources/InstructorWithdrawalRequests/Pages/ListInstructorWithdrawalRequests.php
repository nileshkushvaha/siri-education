<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorWithdrawalRequests\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\InstructorWithdrawalRequests\InstructorWithdrawalRequestResource;
use App\Filament\Support\RelatedResourceLinkGroups;
use Filament\Resources\Pages\ListRecords;

class ListInstructorWithdrawalRequests extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = InstructorWithdrawalRequestResource::class;

    public function getSubheading(): ?string
    {
        return 'Review instructor withdrawal requests and open payout-processing records only when investigation is required.';
    }

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::instructorFinance();
    }
}
