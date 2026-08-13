<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPackageProposals\Pages;

use App\Filament\Resources\InstructorPackageProposals\InstructorPackageProposalResource;
use Filament\Resources\Pages\ListRecords;

class ListInstructorPackageProposals extends ListRecords
{
    protected static string $resource = InstructorPackageProposalResource::class;
}
