<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorCompensationExceptions\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\InstructorCompensationAgreements\InstructorCompensationAgreementResource;
use App\Filament\Resources\InstructorCompensationExceptions\InstructorCompensationExceptionResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use Filament\Resources\Pages\ListRecords;

class ListInstructorCompensationExceptions extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = InstructorCompensationExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(InstructorCompensationAgreementResource::class, 'Back to Compensation Agreements'),
        ]);
    }

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::instructorFinance();
    }
}
