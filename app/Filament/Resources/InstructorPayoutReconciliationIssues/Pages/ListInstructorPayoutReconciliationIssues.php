<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutReconciliationIssues\Pages;

use App\Filament\Resources\InstructorPayoutReconciliationIssues\InstructorPayoutReconciliationIssueResource;
use Filament\Resources\Pages\ListRecords;

class ListInstructorPayoutReconciliationIssues extends ListRecords
{
    protected static string $resource = InstructorPayoutReconciliationIssueResource::class;
}
